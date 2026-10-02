<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\User;
use Core\UserAdminController;
use Core\View;
use PHPUnit\Framework\TestCase;

final class UserAdminControllerTest extends TestCase
{
    private Database $db;
    private UserAdminController $controller;
    private User $admin;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $this->controller = new UserAdminController(
            $this->db,
            $auth,
            new View($base),
            new Csrf($this->db, $auth)
        );
        $this->db->run(
            "INSERT INTO users (id, username, password, role) VALUES ('a1', 'admin', ?, 'admin')",
            [password_hash('adminpw123', PASSWORD_BCRYPT)]
        );
        $this->db->run(
            "INSERT INTO users (id, username, password, role) VALUES ('u1', 'alice', ?, 'user')",
            [password_hash('alicepw123', PASSWORD_BCRYPT)]
        );
        $this->admin = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'a1'"));
        $_POST = [];
    }

    private function countUsers(): int
    {
        return (int)$this->db->one('SELECT COUNT(*) AS c FROM users')['c'];
    }

    public function testStoreCreatesUserWithRole(): void
    {
        $_POST = ['username' => 'bob', 'password' => 'bobpass123', 'password_confirm' => 'bobpass123', 'role' => 'editor'];
        $this->controller->store($this->admin);
        $row = $this->db->one("SELECT role FROM users WHERE username = 'bob'");
        $this->assertSame('editor', (string)$row['role']);
    }

    public function testStoreRejectsDuplicateUsername(): void
    {
        $_POST = ['username' => 'alice', 'password' => 'somepass123', 'password_confirm' => 'somepass123', 'role' => 'user'];
        $this->controller->store($this->admin);
        $this->assertSame(2, $this->countUsers());
    }

    public function testStoreRejectsShortPassword(): void
    {
        $_POST = ['username' => 'bob', 'password' => 'short', 'password_confirm' => 'short', 'role' => 'user'];
        $this->controller->store($this->admin);
        $this->assertSame(2, $this->countUsers());
    }

    public function testStoreRejectsMismatchingPassword(): void
    {
        $_POST = ['username' => 'bob', 'password' => 'bobpass123', 'password_confirm' => 'other12345', 'role' => 'user'];
        $this->controller->store($this->admin);
        $this->assertSame(2, $this->countUsers());
    }

    public function testUpdateRoleChangesRole(): void
    {
        $_POST = ['role' => 'editor'];
        $this->controller->updateRole($this->admin, 'u1');
        $this->assertSame('editor', (string)$this->db->one("SELECT role FROM users WHERE id = 'u1'")['role']);
    }

    public function testUpdateRoleProtectsMainAdmin(): void
    {
        $_POST = ['role' => 'user'];
        $this->controller->updateRole($this->admin, 'a1');
        $this->assertSame('admin', (string)$this->db->one("SELECT role FROM users WHERE id = 'a1'")['role']);
    }

    public function testUpdateRoleProtectsSelf(): void
    {
        $_POST = ['role' => 'user'];
        $this->controller->updateRole($this->admin, 'a1');
        $this->assertSame('admin', (string)$this->db->one("SELECT role FROM users WHERE id = 'a1'")['role']);
    }

    public function testResetPasswordRevokesSessions(): void
    {
        $this->db->run(
            "INSERT INTO sessions (id, user_id, token_hash, expires_at) VALUES ('s1', 'u1', 'hash1', '2999-01-01 00:00:00')"
        );
        $_POST = ['password_new' => 'newpass456'];
        $this->controller->resetPassword($this->admin, 'u1');
        $hash = (string)$this->db->one("SELECT password FROM users WHERE id = 'u1'")['password'];
        $this->assertTrue(password_verify('newpass456', $hash));
        $this->assertSame(0, (int)$this->db->one("SELECT COUNT(*) AS c FROM sessions WHERE user_id = 'u1'")['c']);
    }

    public function testResetPasswordRejectsShort(): void
    {
        $_POST = ['password_new' => 'short'];
        $this->controller->resetPassword($this->admin, 'u1');
        $hash = (string)$this->db->one("SELECT password FROM users WHERE id = 'u1'")['password'];
        $this->assertFalse(password_verify('short', $hash));
    }

    public function testDestroyDeletesUser(): void
    {
        $this->controller->destroy($this->admin, 'u1');
        $this->assertNull($this->db->one("SELECT id FROM users WHERE id = 'u1'"));
    }

    public function testDestroyRemovesMembershipsAndSessions(): void
    {
        $this->db->run(
            "INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'Novel')"
        );
        $this->db->run(
            "INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'u1', 'member')"
        );
        $this->db->run(
            "INSERT INTO sessions (id, user_id, token_hash, expires_at) VALUES ('s1', 'u1', 'hash1', '2999-01-01 00:00:00')"
        );
        $this->controller->destroy($this->admin, 'u1');
        $this->assertSame(0, (int)$this->db->one("SELECT COUNT(*) AS c FROM project_members WHERE user_id = 'u1'")['c']);
        $this->assertSame(0, (int)$this->db->one("SELECT COUNT(*) AS c FROM sessions WHERE user_id = 'u1'")['c']);
    }

    public function testDestroyProtectsSelf(): void
    {
        $this->controller->destroy($this->admin, 'a1');
        $this->assertNotNull($this->db->one("SELECT id FROM users WHERE id = 'a1'"));
    }

    public function testDestroyProtectsMainAdmin(): void
    {
        $this->controller->destroy($this->admin, 'a1');
        $this->assertNotNull($this->db->one("SELECT id FROM users WHERE id = 'a1'"));
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller, $this->admin);
        $_POST = [];
        $_COOKIE = [];
    }
}
