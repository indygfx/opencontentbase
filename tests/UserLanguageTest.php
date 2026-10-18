<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use Core\User;
use PHPUnit\Framework\TestCase;

final class UserLanguageTest extends TestCase
{
    private Database $db;
    private Auth $auth;
    private User $user;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->user = User::fromRow($this->db->one(
            'SELECT id, username, role, language FROM users WHERE id = ?',
            ['u1']
        ));
    }

    public function testLanguageColumnExistsAndDefaultsToNull(): void
    {
        $row = $this->db->one('SELECT language FROM users WHERE id = ?', ['u1']);
        $this->assertNotNull($row);
        $this->assertNull($row['language']);
    }

    public function testSetLanguagePersistsAndIsLoaded(): void
    {
        $this->assertTrue($this->auth->setLanguage($this->user, 'de'));
        $row = $this->db->one('SELECT language FROM users WHERE id = ?', ['u1']);
        $this->assertSame('de', (string)$row['language']);
    }

    public function testSetLanguageRejectsUnknownLanguage(): void
    {
        $this->assertFalse($this->auth->setLanguage($this->user, 'zz'));
        $this->assertFalse($this->auth->setLanguage($this->user, '../etc'));
        $row = $this->db->one('SELECT language FROM users WHERE id = ?', ['u1']);
        $this->assertNull($row['language']);
    }

    public function testUserFromRowExposesLanguage(): void
    {
        $this->db->run("UPDATE users SET language = 'de' WHERE id = 'u1'");
        $user = User::fromRow($this->db->one(
            'SELECT id, username, role, language FROM users WHERE id = ?',
            ['u1']
        ));
        $this->assertSame('de', $user->language());
    }

    public function testUserFromRowToleratesMissingLanguageColumnKey(): void
    {
        $user = User::fromRow([
            'id' => 'u1',
            'username' => 'alice',
            'role' => 'user',
        ]);
        $this->assertNull($user->language());
    }
}
