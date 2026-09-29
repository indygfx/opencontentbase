<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\Database;
use Core\Migrator;
use Core\CoreMigrations;
use Core\User;
use PHPUnit\Framework\TestCase;

final class AuthChangePasswordTest extends TestCase
{
    private Database $db;
    private Auth $auth;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            "INSERT INTO users (id, username, password, role) VALUES ('u1', 'alice', ?, 'admin')",
            [password_hash('oldpass123', PASSWORD_BCRYPT)]
        );
    }

    private function user(): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'u1'"));
    }

    public function testRejectsWrongOldPassword(): void
    {
        $err = $this->auth->changePassword($this->user(), 'wrong', 'newpass456', 'newpass456');
        $this->assertNotNull($err);
    }

    public function testRejectsMismatchingConfirm(): void
    {
        $err = $this->auth->changePassword($this->user(), 'oldpass123', 'newpass456', 'other456');
        $this->assertNotNull($err);
    }

    public function testRejectsTooShortPassword(): void
    {
        $err = $this->auth->changePassword($this->user(), 'oldpass123', 'short', 'short');
        $this->assertNotNull($err);
    }

    public function testRejectsSameAsOldPassword(): void
    {
        $err = $this->auth->changePassword($this->user(), 'oldpass123', 'oldpass123', 'oldpass123');
        $this->assertNotNull($err);
    }

    public function testChangeHashesNewPassword(): void
    {
        $err = $this->auth->changePassword($this->user(), 'oldpass123', 'newpass456', 'newpass456');
        $this->assertNull($err);
        $hash = (string)$this->db->one("SELECT password FROM users WHERE id = 'u1'")['password'];
        $this->assertTrue(password_verify('newpass456', $hash));
        $this->assertFalse(password_verify('oldpass123', $hash));
    }

    public function testChangeRevokesOtherSessionsButKeepsCurrent(): void
    {
        $raw = 'keep-token';
        $this->db->run(
            'INSERT INTO sessions (id, user_id, token_hash, expires_at) VALUES
             (\'s1\', \'u1\', ?, \'2999-01-01 00:00:00\'),
             (\'s2\', \'u1\', \'revoke-hash\', \'2999-01-01 00:00:00\')',
            [hash('sha256', $raw)]
        );
        $_COOKIE['cb_session'] = $raw;
        $err = $this->auth->changePassword($this->user(), 'oldpass123', 'newpass456', 'newpass456');
        $this->assertNull($err);
        $ids = array_column($this->db->all("SELECT id FROM sessions WHERE user_id = 'u1'"), 'id');
        $this->assertSame(['s1'], $ids);
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth);
        $_COOKIE = [];
    }
}
