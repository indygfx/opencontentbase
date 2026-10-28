<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\LoginThrottler;
use Core\Migrator;
use Core\Router;
use PHPUnit\Framework\TestCase;

/**
 * Login rate-limiting: 5 failures within 15 minutes block the username
 * for 1 minute, doubling per further 5 failures (capped at 15 min);
 * success clears the rows; only the last 24 h count.
 */
final class LoginThrottlerTest extends TestCase
{
    private Database $db;
    private LoginThrottler $throttler;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/cb_throttle_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->throttler = new LoginThrottler($this->db);
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->throttler);
    }

    private function record(string $username, int $times, int $ageSeconds = 0): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->throttler->recordFailure($username, '1.2.3.4');
            if ($ageSeconds > 0 && $i === 0) {
                // Age the very first failure beyond the window so the
                // newest rows decide the block.
            }
        }
    }

    public function testNotBlockedWithoutFailures(): void
    {
        $this->assertNull($this->throttler->isBlocked('alice', '1.2.3.4'));
    }

    public function testBlockedAfterFiveFailuresWithinWindow(): void
    {
        $this->record('alice', 4);
        $this->assertNull($this->throttler->isBlocked('alice', '1.2.3.4'));
        $this->record('alice', 1); // 5th failure triggers the block
        $remaining = $this->throttler->isBlocked('alice', '1.2.3.4');
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(0, $remaining);
        $this->assertLessThanOrEqual(60, $remaining);
    }

    public function testEscalationDoublesPerFurtherFiveFailures(): void
    {
        // 10 failures = 2nd step = 2 minutes block.
        $this->record('alice', 10);
        $remaining = $this->throttler->isBlocked('alice', '1.2.3.4');
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(60, $remaining);
        $this->assertLessThanOrEqual(120, $remaining);

        // 15 failures = 3rd step = 4 minutes.
        $this->record('alice', 5);
        $remaining = $this->throttler->isBlocked('alice', '1.2.3.4');
        $this->assertNotNull($remaining);
        $this->assertGreaterThan(120, $remaining);
        $this->assertLessThanOrEqual(240, $remaining);
    }

    public function testCapAtFifteenMinutes(): void
    {
        // 30 failures = 6th step, capped at the 5th (900 s).
        $this->record('alice', 30);
        $remaining = $this->throttler->isBlocked('alice', '1.2.3.4');
        $this->assertNotNull($remaining);
        $this->assertLessThanOrEqual(900, $remaining);
    }

    public function testSuccessClearsTheUsernameRows(): void
    {
        $this->record('alice', 5);
        $this->assertNotNull($this->throttler->isBlocked('alice', '1.2.3.4'));
        $this->throttler->recordSuccess('alice');
        $this->assertNull($this->throttler->isBlocked('alice', '1.2.3.4'));
        // Other usernames keep their rows.
        $this->record('bob', 5);
        $this->throttler->recordSuccess('alice');
        $this->assertNotNull($this->throttler->isBlocked('bob', '1.2.3.4'));
    }

    public function testFailuresOutsideWindowDoNotCount(): void
    {
        // 4 fresh failures + 1 failure 20 minutes old = not blocked.
        $this->throttler->recordFailure('alice', '1.2.3.4');
        $this->db->run(
            "UPDATE login_attempts SET created_at = ? WHERE username = 'alice'",
            [gmdate('Y-m-d H:i:s', time() - 20 * 60)]
        );
        $this->record('alice', 4);
        $this->assertNull($this->throttler->isBlocked('alice', '1.2.3.4'));
    }

    public function testExpiredBlockDoesNotBlock(): void
    {
        // 5 failures, but the last one is 5 minutes old - the 60 s block
        // has expired.
        $this->record('alice', 5);
        $this->db->run(
            "UPDATE login_attempts SET created_at = ? WHERE username = 'alice'",
            [gmdate('Y-m-d H:i:s', time() - 5 * 60)]
        );
        $this->assertNull($this->throttler->isBlocked('alice', '1.2.3.4'));
    }

    public function testRowsOlderThan24HoursArePruned(): void
    {
        $this->record('alice', 1);
        $this->db->run(
            "UPDATE login_attempts SET created_at = ? WHERE username = 'alice'",
            [gmdate('Y-m-d H:i:s', time() - 25 * 3600)]
        );
        // The next write prunes the old row.
        $this->record('bob', 1);
        $this->assertSame(0, (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM login_attempts WHERE username = 'alice'"
        )['c']);
    }

    public function testLoginAttemptsTableHasIndexes(): void
    {
        $indexes = $this->db->all(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = 'login_attempts'"
        );
        $names = array_column($indexes, 'name');
        $this->assertContains('idx_login_attempts_username', $names);
        $this->assertContains('idx_login_attempts_ip', $names);
    }

    public function testBlockedLoginShowsErrorEvenWithCorrectCredentials(): void
    {
        // AuthController integration: while blocked, even correct
        // credentials are rejected with the throttle message.
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('correctpw1', PASSWORD_BCRYPT), 'user']
        );
        $controller = new \Core\AuthController(
            $auth,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            null,
            $this->throttler
        );
        // 5 wrong attempts block the username.
        for ($i = 0; $i < 5; $i++) {
            $_POST = ['username' => 'alice', 'password' => 'wrongguess'];
            $response = $controller->login();
            $this->assertSame(401, $response->status());
        }
        // Correct credentials while blocked: throttle message, 429.
        $_POST = ['username' => 'alice', 'password' => 'correctpw1'];
        $response = $controller->login();
        $this->assertSame(429, $response->status());
        // The error page shows the throttle message with a {seconds}
        // placeholder count; without an initialized Translator t()
        // returns the raw key, so compare digit-stripped (the seconds
        // number varies with test runtime).
        $stripped = (string)preg_replace('/\d+/', '', $response->body());
        $this->assertStringContainsString('login_throttled', $stripped);
        // No session was created for the blocked login.
        $this->assertSame(0, (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM sessions'
        )['c']);
    }

    public function testSuccessfulLoginRecordsSuccess(): void
    {
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('correctpw1', PASSWORD_BCRYPT), 'user']
        );
        $controller = new \Core\AuthController(
            $auth,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            null,
            $this->throttler
        );
        // 4 failures (not yet blocking), then a successful login.
        for ($i = 0; $i < 4; $i++) {
            $_POST = ['username' => 'alice', 'password' => 'wrongguess'];
            $controller->login();
        }
        $_POST = ['username' => 'alice', 'password' => 'correctpw1'];
        $response = $controller->login();
        $this->assertSame(302, $response->status());
        // Success cleared the recorded failures.
        $this->assertSame(0, (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM login_attempts'
        )['c']);
    }
}
