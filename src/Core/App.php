<?php

declare(strict_types=1);

namespace Core;

use Pages\PagesModule;

final class App
{
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
    }

    public function boot(): void
    {
        $this->migrator->migrate('core', CoreMigrations::migrations());

        $pages = new PagesModule($this->db, $this->view, $this->renderer, $this->deleter, $this->csrf);
        $this->registry->register($pages);
        $this->migrator->migrate($pages->id(), $pages->migrations());

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

    public function ensureInitialAdmin(): void
    {
        $this->auth->ensureInitialAdmin();
    }

    public function run(): void
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

        try {
            $route = $this->router->dispatch($method, $path);
        } catch (RouteNotFoundException) {
            $this->fail(404, 'Nicht gefunden');
            return;
        }

        $user = $this->auth->user();
        if ($route['roles'] !== [] && ($user === null || !$this->hasRole($user, $route['roles']))) {
            if ($user === null) {
                Response::redirect('/login')->send();
                return;
            }
            $this->fail(403, 'Zugriff verweigert');
            return;
        }

        $handler = $route['handler'];
        if ($method === 'POST' && !$this->csrf->validate()) {
            $this->fail(403, 'Ungültiges oder fehlendes CSRF-Token.');
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
        $controller = new AuthController($this->auth, $this->view, $this->csrf);
        $this->router->get('/login', fn ($params, $user) => $controller->showLogin());
        $this->router->post('/login', fn ($params, $user) => $controller->login());
        $this->router->post('/logout', fn ($params, $user) => $controller->logout());
        $this->router->get('/profile', fn ($params, $user) => $controller->showProfile($user));
        $this->router->post('/profile/password', fn ($params, $user) => $controller->changePassword($user));
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
