<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\SetupController;
use PHPUnit\Framework\TestCase;

/**
 * First-start setup wizard: while the users table is empty every route
 * redirects to /setup (App-level gate), the form creates the first
 * admin, and once any user exists /setup is 404 forever.
 */
final class SetupFlowTest extends TestCase
{
    private Database $db;
    private SetupController $controller;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_setup_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new \Core\Auth($this->db);
        $this->controller = new SetupController($this->db, new \Core\View($base), new Csrf($this->db, $auth));
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller);
        $_POST = [];
        $_GET = [];
    }

    public function testEmptyDatabaseShowsSetupForm(): void
    {
        $response = $this->controller->show();
        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertStringContainsString(t('core', 'setup_title'), $body);
        $this->assertStringContainsString(t('core', 'setup_submit'), $body);
        $this->assertStringContainsString('name="username"', $body);
        $this->assertStringContainsString('name="password"', $body);
        $this->assertStringContainsString('name="password_confirm"', $body);
    }

    public function testPostCreatesFirstAdminAndRedirectsToLogin(): void
    {
        $_POST = ['username' => 'firstadmin', 'password' => 'secret123', 'password_confirm' => 'secret123'];
        $response = $this->controller->create();
        $this->assertSame(302, $response->status());
        $this->assertSame('/login?setup=done', $response->header('Location'));
        $row = $this->db->one('SELECT username, role FROM users');
        $this->assertSame('firstadmin', (string)$row['username']);
        $this->assertSame('admin', (string)$row['role']);
    }

    public function testSetupGoneOnceAnyUserExists(): void
    {
        $_POST = ['username' => 'firstadmin', 'password' => 'secret123', 'password_confirm' => 'secret123'];
        $this->controller->create();
        $this->assertSame(404, $this->controller->show()->status());
        $this->assertSame(404, $this->controller->create()->status());
    }

    public function testValidationShortUsername(): void
    {
        $_POST = ['username' => 'ab', 'password' => 'secret123', 'password_confirm' => 'secret123'];
        $response = $this->controller->create();
        $this->assertSame(422, $response->status());
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM users')['c']);
    }

    public function testValidationShortPassword(): void
    {
        $_POST = ['username' => 'firstadmin', 'password' => 'short', 'password_confirm' => 'short'];
        $response = $this->controller->create();
        $this->assertSame(422, $response->status());
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM users')['c']);
    }

    public function testValidationConfirmMismatch(): void
    {
        $_POST = ['username' => 'firstadmin', 'password' => 'secret123', 'password_confirm' => 'different'];
        $response = $this->controller->create();
        $this->assertSame(422, $response->status());
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM users')['c']);
    }

    public function testValidationUsernameCharset(): void
    {
        $_POST = ['username' => 'bad name!', 'password' => 'secret123', 'password_confirm' => 'secret123'];
        $response = $this->controller->create();
        $this->assertSame(422, $response->status());
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM users')['c']);
    }
    public function testPostSetupCreatesExactlyOneAdminRole(): void
    {
        // The first user is always an admin, regardless of anything else.
        $_POST = ['username' => 'firstadmin', 'password' => 'secret123', 'password_confirm' => 'secret123'];
        $this->controller->create();
        $roles = $this->db->all('SELECT role FROM users');
        $this->assertSame(['admin'], array_column($roles, 'role'));
    }
}
