<?php
declare(strict_types=1);

namespace Tests\Pages;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\Response;
use Core\Router;
use Core\User;
use Pages\PagesModule;
use PHPUnit\Framework\TestCase;
use Writing\WritingModule;

/**
 * Root URL and pages module routing: the root opens the writing app for
 * every logged-in user, while the pages list, creation and editing stay
 * admin-only (the intended use of pages is an open decision).
 */
final class RootAndPagesAccessTest extends TestCase
{
    private Database $db;
    private Router $router;
    private ModuleRegistry $registry;
    private User $admin;
    private User $editor;
    private User $user;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_root_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $this->registry = new ModuleRegistry();
        $renderer = new \Core\ContentRenderer($this->db, $this->registry);
        $pages = new PagesModule($this->db, new \Core\View($base), $renderer, new \Core\ObjectDeleter($this->db, $this->registry), new Csrf($this->db, $auth));
        $writing = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        $this->registry->register($pages);
        $this->registry->register($writing);
        (new Migrator($this->db))->migrate('pages', $pages->migrations());
        (new Migrator($this->db))->migrate('writing', $writing->migrations());

        $this->router = new Router();
        foreach ($this->registry->all() as $module) {
            foreach ($module->routes($this->router) as $route) {
                $this->router->{$route['method'] === 'GET' ? 'get' : 'post'}(
                    $route['pattern'],
                    $route['handler'],
                    $route['roles']
                );
            }
        }

        foreach ([['a1', 'admin', 'admin'], ['e1', 'editor', 'editor'], ['u1', 'user', 'user']] as [$id, $name, $role]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), $role]
            );
        }
        $this->admin = $this->user('a1');
        $this->editor = $this->user('e1');
        $this->user = $this->user('u1');
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->router, $this->registry, $this->admin, $this->editor, $this->user);
        $_POST = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one('SELECT id, username, role FROM users WHERE id = ?', [$id]));
    }

    /** @return array{handler: callable, params: array<string, string>, roles: list<string>} */
    private function dispatch(string $method, string $path): array
    {
        return $this->router->dispatch($method, $path);
    }

    public function testRootRedirectsToWritingForRegularUser(): void
    {
        $route = $this->dispatch('GET', '/');
        $this->assertSame(['user'], $route['roles']);
        $response = ($route['handler'])($route['params'], $this->user);
        $this->assertSame(302, $response->status());
        $this->assertSame('/writing', $response->header('Location'));
    }

    public function testRootRedirectsToWritingForAdminToo(): void
    {
        $route = $this->dispatch('GET', '/');
        $response = ($route['handler'])($route['params'], $this->admin);
        $this->assertSame(302, $response->status());
        $this->assertSame('/writing', $response->header('Location'));
    }

    public function testPagesListRequiresAdminRole(): void
    {
        $this->assertSame(['admin'], $this->dispatch('GET', '/pages')['roles']);
        $this->assertSame(['admin'], $this->dispatch('GET', '/pages/new')['roles']);
        $this->assertSame(['admin'], $this->dispatch('POST', '/pages')['roles']);
        $this->assertSame(['admin'], $this->dispatch('GET', '/pages/xyz/edit')['roles']);
        $this->assertSame(['admin'], $this->dispatch('POST', '/pages/xyz')['roles']);
        $this->assertSame(['admin'], $this->dispatch('POST', '/pages/xyz/delete')['roles']);
    }

    public function testAdminCanListAndEditPages(): void
    {
        $route = $this->dispatch('GET', '/pages');
        $response = ($route['handler'])($route['params'], $this->admin);
        $this->assertSame(200, $response->status());

        $_POST = ['slug' => '', 'title' => 'Imprint', 'body' => 'Legal notes.'];
        $store = $this->dispatch('POST', '/pages');
        $response = ($store['handler'])($store['params'], $this->admin);
        $this->assertSame(302, $response->status());
        $this->assertSame('/pages/imprint', $response->header('Location'));

        $edit = $this->dispatch('GET', '/pages/imprint/edit');
        $response = ($edit['handler'])($edit['params'], $this->admin);
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('data-editor="tiptap"', $response->body());
        $this->assertStringContainsString(t('pages', 'edit_page', ['title' => 'Imprint']), $response->body());
    }

    public function testPageEditFormKeepsInputOnSlugError(): void
    {
        $_POST = ['slug' => 'imprint', 'title' => 'Imprint', 'body' => 'Legal notes.'];
        $store = $this->dispatch('POST', '/pages');
        ($store['handler'])($store['params'], $this->admin);
        // Second store with the same slug must fail and keep the input.
        $_POST = ['slug' => 'imprint', 'title' => 'Imprint 2', 'body' => 'More notes.'];
        $response = ($store['handler'])($store['params'], $this->admin);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Imprint 2', $response->body());
        $this->assertStringContainsString('More notes.', $response->body());
        $this->assertStringContainsString(t('pages', 'slug_taken'), $response->body());
    }

    public function testPageEditPageShowsDeleteForExistingPage(): void
    {
        $_POST = ['slug' => '', 'title' => 'Imprint', 'body' => 'Legal notes.'];
        $store = $this->dispatch('POST', '/pages');
        ($store['handler'])($store['params'], $this->admin);
        $edit = $this->dispatch('GET', '/pages/imprint/edit');
        $response = ($edit['handler'])($edit['params'], $this->admin);
        $this->assertStringContainsString('/pages/imprint/delete', $response->body());
        $this->assertStringContainsString('/pages">&larr;', $response->body());
    }

    public function testPagesListShowsEditLinkAndNewPageButton(): void
    {
        $_POST = ['slug' => '', 'title' => 'Imprint', 'body' => 'Legal notes.'];
        $store = $this->dispatch('POST', '/pages');
        ($store['handler'])($store['params'], $this->admin);
        $list = $this->dispatch('GET', '/pages');
        $response = ($list['handler'])($list['params'], $this->admin);
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('/pages/imprint/edit', $response->body());
        $this->assertStringContainsString('href="/pages/new"', $response->body());
    }

    public function testPageShowHasEditLinkForAdmin(): void
    {
        $_POST = ['slug' => '', 'title' => 'Imprint', 'body' => 'Legal notes.'];
        $store = $this->dispatch('POST', '/pages');
        ($store['handler'])($store['params'], $this->admin);
        $show = $this->dispatch('GET', '/pages/imprint');
        $response = ($show['handler'])($show['params'], $this->admin);
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('/pages/imprint/edit', $response->body());
    }
}
