<?php

declare(strict_types=1);

namespace Core;

use Pages\PagesModule;
use Writing\WritingModule;

/**
 * Bootstraps the translator from the request: user account language,
 * then the language cookie, then the browser's Accept-Language header.
 */
final class App
{
    private const LANG_COOKIE = 'cb_lang';

    private Database $db;
    private Router $router;
    private Migrator $migrator;
    private Auth $auth;
    private Csrf $csrf;
    private View $view;
    private ModuleRegistry $registry;
    private ContentRenderer $renderer;
    private ObjectDeleter $deleter;

    public function __construct(private string $basePath)
    {
        $this->db = new Database($this->basePath . '/data/contentbase.sqlite');
        $this->router = new Router();
        $this->migrator = new Migrator($this->db);
        $this->auth = new Auth($this->db);
        $this->csrf = new Csrf($this->db, $this->auth);
        $this->view = new View($this->basePath);
        $this->registry = new ModuleRegistry();
        $this->renderer = new ContentRenderer($this->db, $this->registry);
        $this->deleter = new ObjectDeleter($this->db, $this->registry);
        Translator::init($this->basePath . '/lang');
    }

    /**
     * Picks the UI language: user account setting, language cookie,
     * then the browser's Accept-Language header, then the English default.
     */
    private function detectLanguage(): string
    {
        $available = [];
        foreach (glob($this->basePath . '/lang/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $available[] = basename($dir);
        }
        $cookie = $_COOKIE[self::LANG_COOKIE] ?? '';
        $cookie = is_string($cookie) && in_array($cookie, $available, true) ? $cookie : null;
        $user = $this->auth->user();
        if ($user !== null && $user->language() !== null && in_array($user->language(), $available, true)) {
            return $user->language();
        }
        if ($cookie !== null) {
            return $cookie;
        }
        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        foreach (explode(',', (string)$header) as $part) {
            $code = strtolower(trim(explode(';', trim($part))[0]));
            $code = preg_replace('/[^a-z0-9_-]/', '', $code) ?? '';
            if ($code !== '' && in_array($code, $available, true)) {
                return $code;
            }
        }
        return Translator::FALLBACK;
    }

    public function boot(): void
    {
        $this->migrator->migrate('core', CoreMigrations::migrations());

        $pages = new PagesModule($this->db, $this->view, $this->renderer, $this->deleter, $this->csrf);
        $this->registry->register($pages);
        $this->migrator->migrate($pages->id(), $pages->migrations());
        $writing = new WritingModule($this->db, $this->view, $this->csrf, $this->renderer, $this->basePath);
        $this->registry->register($writing);
        $this->migrator->migrate($writing->id(), $writing->migrations());

        $this->registerCoreRoutes();
        foreach ($this->registry->all() as $module) {
            foreach ($module->routes($this->router) as $route) {
                $this->router->{$route['method'] === 'GET' ? 'get' : 'post'}(
                    $route['pattern'],
                    $route['handler'],
                    $route['roles']
                );
            }
        }
    }

    public function run(): void
    {
        Translator::instance()?->setLang($this->detectLanguage());
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        // First-start setup gate: while the users table is empty, every
        // request redirects to the setup wizard. The users table is the
        // single source of truth - no cookies or flags involved.
        if ($this->usersTableIsEmpty() && $path !== '/setup') {
            Response::redirect('/setup')->send();
            return;
        }

        try {
            $route = $this->router->dispatch($method, $path);
        } catch (RouteNotFoundException) {
            $this->fail(404, 'Not found');
            return;
        }

        $user = $this->auth->user();
        if ($route['roles'] !== [] && ($user === null || !$this->hasRole($user, $route['roles']))) {
            if ($user === null) {
                Response::redirect('/login')->send();
                return;
            }
            $this->fail(403, 'Access denied');
            return;
        }

        $handler = $route['handler'];
        if ($method === 'POST' && !$this->csrf->validate()) {
            $this->fail(403, 'Invalid or missing CSRF token.');
            return;
        }
        $response = $handler($route['params'], $user);
        $response->send();
    }

    /** @param list<string> $roles */
    private function hasRole(User $user, array $roles): bool
    {
        foreach ($roles as $role) {
            if ($user->hasAtLeast($role)) {
                return true;
            }
        }
        return false;
    }

    private function registerCoreRoutes(): void
    {
        $throttler = new LoginThrottler($this->db);
        $controller = new AuthController($this->auth, $this->view, $this->csrf, Translator::instance(), $throttler);
        $setup = new SetupController($this->db, $this->view, $this->csrf);
        $this->router->get('/setup', fn ($params, $user) => $setup->show());
        $this->router->post('/setup', fn ($params, $user) => $setup->create());
        $this->router->get('/login', fn ($params, $user) => $controller->showLogin());
        $this->router->post('/login', fn ($params, $user) => $controller->login());
        $this->router->post('/logout', fn ($params, $user) => $controller->logout());
        $this->router->get('/profile', fn ($params, $user) => $controller->showProfile($user));
        $this->router->post('/profile/password', fn ($params, $user) => $controller->changePassword($user));
        $this->router->post('/profile/language', fn ($params, $user) => $controller->changeLanguage($user), ['user']);

        $admin = new UserAdminController($this->db, $this->auth, $this->view, $this->csrf);
        $this->router->get('/users', fn ($params, $user) => $admin->index($user), ['admin']);
        $this->router->post('/users', fn ($params, $user) => $admin->store($user), ['admin']);
        $this->router->post('/users/{id}/role', fn ($params, $user) => $admin->updateRole($user, $params['id']), ['admin']);
        $this->router->post('/users/{id}/password', fn ($params, $user) => $admin->resetPassword($user, $params['id']), ['admin']);
        $this->router->post('/users/{id}/delete', fn ($params, $user) => $admin->destroy($user, $params['id']), ['admin']);
    }

    /** True while no user exists at all - the first-start setup state. */
    private function usersTableIsEmpty(): bool
    {
        $row = $this->db->one('SELECT COUNT(*) AS c FROM users');
        return (int)($row['c'] ?? 0) === 0;
    }

    private function fail(int $code, string $message): void
    {
        Response::html($this->view->render('templates/layout.php', [
            'title' => (string)$code,
            'user' => $this->auth->user(),
            'csrf' => $this->csrf,
            'content' => $this->view->render('templates/error.php', [
                'code' => $code,
                'message' => $message,
            ]),
        ]), $code)->send();
    }
}
