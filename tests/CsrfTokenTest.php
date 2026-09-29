<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use PHPUnit\Framework\TestCase;

final class CsrfTokenTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
    }

    public function testGuestTokenIsGeneratedAndReusable(): void
    {
        $csrf = new Csrf($this->db, new Auth($this->db));
        $token = $csrf->token();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame($token, $csrf->token());
        $this->assertSame($token, $_COOKIE['cb_csrf']);
    }

    public function testGuestTokenValidatesWithoutSession(): void
    {
        $csrf = new Csrf($this->db, new Auth($this->db));
        $token = $csrf->token();
        $_POST['_csrf'] = $token;
        $this->assertTrue($csrf->validate());
        $_POST['_csrf'] = str_repeat('a', 64);
        $this->assertFalse($csrf->validate());
        $_POST['_csrf'] = '';
        $this->assertFalse($csrf->validate());
    }

    public function testSessionTokenIsValidatedFromDatabase(): void
    {
        $this->db->run(
            "INSERT INTO users (id, username, password, role) VALUES ('u1', 'alice', ?, 'admin')",
            [password_hash('x', PASSWORD_BCRYPT)]
        );
        $token = 'sessiontoken';
        $this->db->run(
            'INSERT INTO sessions (id, user_id, token_hash, expires_at, csrf_token)
             VALUES (\'s1\', \'u1\', ?, \'2999-01-01 00:00:00\', ?)',
            [hash('sha256', $token), str_repeat('f', 64)]
        );
        $csrf = new Csrf($this->db, new Auth($this->db));
        $_COOKIE['cb_session'] = $token;
        $auth = new Auth($this->db);
        $this->assertSame('s1', $auth->currentSessionId());
        $this->assertSame(str_repeat('f', 64), $auth->currentCsrfToken());
        $_POST['_csrf'] = str_repeat('f', 64);
        $this->assertTrue($csrf->validate());
    }

    protected function tearDown(): void
    {
        unset($this->db);
        $_COOKIE = [];
        $_POST = [];
    }
}
