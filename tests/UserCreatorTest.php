<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use Core\UserCreator;
use PHPUnit\Framework\TestCase;

/**
 * Shared user creation for setup, admin UI and CLI: validation rules
 * (length, charset, confirm, role allowlist, duplicates) and insert.
 */
final class UserCreatorTest extends TestCase
{
    private Database $db;
    private UserCreator $creator;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/cb_creator_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->creator = new UserCreator($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->creator);
    }

    public function testCreatesValidUserWithDefaultHash(): void
    {
        $error = null;
        $id = $this->creator->create('alice', 'secret123', 'secret123', 'user', $error);
        $this->assertNotNull($id);
        $this->assertNull($error);
        $row = $this->db->one('SELECT username, role, password FROM users WHERE id = ?', [$id]);
        $this->assertSame('alice', (string)$row['username']);
        $this->assertSame('user', (string)$row['role']);
        $this->assertTrue(password_verify('secret123', (string)$row['password']));
    }

    public function testRoleOption(): void
    {
        $error = null;
        $id = $this->creator->create('boss', 'secret123', 'secret123', 'admin', $error);
        $this->assertNotNull($id);
        $this->assertSame('admin', (string)$this->db->one('SELECT role FROM users WHERE id = ?', [$id])['role']);
    }

    public function testDuplicateUsernameRejected(): void
    {
        $error = null;
        $this->creator->create('alice', 'secret123', 'secret123', 'user', $error);
        $second = $this->creator->create('alice', 'otherpass1', 'otherpass1', 'user', $error);
        $this->assertNull($second);
        $this->assertSame(t('core', 'users_error_duplicate'), $error);
        $this->assertSame(1, (int)$this->db->one('SELECT COUNT(*) AS c FROM users')['c']);
    }

    public function testShortUsernameRejected(): void
    {
        $error = null;
        $id = $this->creator->create('ab', 'secret123', 'secret123', 'user', $error);
        $this->assertNull($id);
        $this->assertSame(t('core', 'users_error_username_short'), $error);
    }

    public function testInvalidCharsetRejected(): void
    {
        $error = null;
        $id = $this->creator->create('bad name!', 'secret123', 'secret123', 'user', $error);
        $this->assertNull($id);
        $this->assertSame(t('core', 'users_error_username_chars'), $error);
    }

    public function testShortPasswordRejected(): void
    {
        $error = null;
        $id = $this->creator->create('alice', 'short', 'short', 'user', $error);
        $this->assertNull($id);
        $this->assertSame(t('core', 'users_error_password'), $error);
    }

    public function testConfirmMismatchRejected(): void
    {
        $error = null;
        $id = $this->creator->create('alice', 'secret123', 'different1', 'user', $error);
        $this->assertNull($id);
        $this->assertSame(t('core', 'users_error_confirm'), $error);
    }

    public function testInvalidRoleRejected(): void
    {
        $error = null;
        $id = $this->creator->create('alice', 'secret123', 'secret123', 'superadmin', $error);
        $this->assertNull($id);
        $this->assertSame(t('core', 'users_error_role'), $error);
    }
}
