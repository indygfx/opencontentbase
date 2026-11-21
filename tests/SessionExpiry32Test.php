<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Auth;
use Core\Csrf;
use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use Core\Translator;
use Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Prompt 32 - CSRF/session expiry: token stability (single source of truth in
 * the session, cookie synced), never-silent 403 handling, draft rescue, robust
 * logout, /session-check heartbeat endpoint.
 */
final class SessionExpiry32Test extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_p32_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['a1', 'alice', password_hash('x1234567', PASSWORD_BCRYPT), 'admin']
        );
        $_COOKIE = [];
        $_POST = [];
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth);
        $_COOKIE = [];
        $_POST = [];
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function makeSession(string $sessionToken = 'tok123', string $csrfToken = ''): string
    {
        $csrfToken = $csrfToken !== '' ? $csrfToken : str_repeat('a', 64);
        $_COOKIE['cb_session'] = $sessionToken;
        $this->db->run(
            'INSERT INTO sessions (id, user_id, token_hash, expires_at, csrf_token) VALUES (?, ?, ?, ?, ?)',
            ['s1', 'a1', hash('sha256', $sessionToken), gmdate('Y-m-d H:i:s', time() + 86400), $csrfToken]
        );
        return $csrfToken;
    }

    // ---- Part 1: token stability ----

    public function testSessionTokenIsSingleSourceOfTruthAndCookieIsSynced(): void
    {
        $csrf = $this->makeSession('tok123', str_repeat('b', 64));
        $_COOKIE['cb_csrf'] = str_repeat('c', 64); // stale/mismatched cookie
        $csrfObj = new Csrf($this->db, $this->auth);
        $token = $csrfObj->token();
        $this->assertSame($csrf, $token, 'token() must return the SESSION token, not the cookie');
        $this->assertSame($csrf, $_COOKIE['cb_csrf'], 'cookie gets synced to the session token');
        $_POST['_csrf'] = $csrf;
        $this->assertTrue($csrfObj->validate(), 'validate passes with the session token even though the cookie differed');
    }

    public function testExpiredSessionMeansLoggedOutAndCookieFallback(): void
    {
        $this->makeSession('tok123', str_repeat('b', 64));
        $this->db->run("UPDATE sessions SET expires_at = ? WHERE id = 's1'", [gmdate('Y-m-d H:i:s', time() - 10)]);
        $this->assertNull($this->auth->user(), 'expired session = logged out (navigation guard redirects to login)');
        $csrfObj = new Csrf($this->db, $this->auth);
        $_COOKIE['cb_csrf'] = str_repeat('b', 64);
        $_POST['_csrf'] = str_repeat('b', 64);
        $this->assertTrue($csrfObj->validate(), 'no session row -> cookie fallback still validates');
    }

    // ---- Part 2/4: App-level behavior (static) + logout functional ----

    public function testAppCsrfFailBranchAndLogoutBypass(): void
    {
        $app = (string)file_get_contents($this->base . '/src/Core/App.php');
        $this->assertStringContainsString("\$path !== '/logout'", $app, 'logout bypasses the CSRF check');
        $this->assertStringContainsString('csrfFail(', $app);
        $this->assertStringContainsString("'code' => 'csrf_expired'", $app, 'AJAX 403 carries the JSON hint');
        $this->assertStringContainsString("'return=' . rawurlencode(\$path) . '&expired=1'", $app);
        $this->assertStringContainsString("'/session-check'", $app, 'heartbeat endpoint registered');
        $this->assertMatchesRegularExpression('/204/', $app);
        $this->assertMatchesRegularExpression('/401/', $app);
    }

    public function testLogoutDestroysSessionEvenWithInvalidCsrf(): void
    {
        $this->makeSession('tok123', str_repeat('b', 64));
        $_COOKIE['cb_csrf'] = str_repeat('c', 64); // mismatched
        $_POST['_csrf'] = 'garbage';
        $csrf = new Csrf($this->db, $this->auth);
        $this->assertFalse($csrf->validate());
        // the controller destroys the session regardless (App routes /logout past the CSRF check)
        $controller = new \Core\AuthController($this->auth, new View($this->base), $csrf, Translator::instance());
        $response = $controller->logout();
        $this->assertSame(302, $response->status());
        $this->assertSame('/login', $response->header('Location'));
        $this->assertNull($this->db->one("SELECT id FROM sessions WHERE id = 's1'"), 'session row deleted despite invalid token');
    }

    // ---- Part 2: login return parameter ----

    public function testLoginReturnParamSanitized(): void
    {
        $csrf = new Csrf($this->db, $this->auth);
        $controller = new \Core\AuthController($this->auth, new View($this->base), $csrf, Translator::instance());
        $_POST = ['username' => 'alice', 'password' => 'x1234567', 'return' => '/writing/p1/chapters/ch1/edit'];
        $r = $controller->login();
        $this->assertSame('/writing/p1/chapters/ch1/edit', $r->header('Location'), 'valid same-origin return honored');
        $_POST['return'] = 'https://evil.example.com/';
        $r = $controller->login();
        $this->assertSame('/writing', $r->header('Location'), 'external return rejected');
        $_POST['return'] = '//evil.example.com';
        $r = $controller->login();
        $this->assertSame('/writing', $r->header('Location'), 'protocol-relative return rejected');
    }

    public function testLoginPageShowsExpiredNoticeAndReturnField(): void
    {
        $csrf = new Csrf($this->db, $this->auth);
        $controller = new \Core\AuthController($this->auth, new View($this->base), $csrf, Translator::instance());
        $_GET = ['expired' => '1', 'return' => '/writing/p1'];
        $body = $controller->showLogin()->body();
        $this->assertStringContainsString('Session expired', $body);
        $this->assertStringContainsString('name="return" value="/writing/p1"', $body);
        $_GET = [];
    }

    // ---- Part 3/5: client-side wiring (static) ----

    public function testAppJsSessionHandling(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString('res.status === 403 && method === \'POST\'', $js, 'global fetch wrapper catches POST 403');
        $this->assertStringContainsString('window.fetch = function', $js);
        $this->assertStringContainsString("'draft-rescue/'", $js, 'rescue stash key');
        $this->assertStringContainsString("code === 'csrf_expired'", $js, 'draft save stays quiet on csrf 403 (no double toast)');
        $this->assertStringContainsString('window.cbClearRescue', $js, 'rescue cleared on successful save');
        $this->assertStringContainsString("document.getElementById('draft-rescue-banner')", $js);
        $this->assertStringContainsString('data-rescue-restore', $js);
        $this->assertStringContainsString('data-rescue-discard', $js);
        $this->assertStringContainsString('visibilitychange', $js);
        $this->assertStringContainsString('var THROTTLE_MS = 5 * 60 * 1000;', $js, 'heartbeat throttled to 5 min');
        $this->assertStringContainsString("'/session-check'", $js);
        $this->assertStringContainsString('!navigator.onLine', $js, 'skipped while offline');
        $this->assertStringContainsString("'/login?return='", $js);
    }

    public function testSwSessionCheckNetworkOnlyAndV10(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v10'", $sw);
        $pos = strpos($sw, "path === '/session-check'");
        $this->assertNotFalse($pos);
        $this->assertLessThan(strpos($sw, 'ASSET_PREFIX) === 0'), $pos, 'session-check passthrough before any caching branch');
    }

    public function testTemplatesCarryRescueBannerAndSessionExpiredAttr(): void
    {
        $tpl = (string)file_get_contents($this->base . '/modules/Writing/templates/chapter_edit.php');
        $this->assertStringContainsString('id="draft-rescue-banner" hidden', $tpl);
        $this->assertStringContainsString('data-found-template', $tpl);
        $this->assertStringContainsString('data-rescue-restore', $tpl);
        $this->assertStringContainsString('data-rescue-discard', $tpl);
        $layout = (string)file_get_contents($this->base . '/templates/layout.php');
        $this->assertStringContainsString('data-session-expired', $layout);
        $login = (string)file_get_contents($this->base . '/templates/login.php');
        $this->assertStringContainsString('name="return"', $login);
    }

    public function testI18nParity(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $core = (string)file_get_contents($this->base . '/lang/' . $code . '/core.php');
            $this->assertStringContainsString("'session_expired'", $core, $code . '/core.php');
            $writing = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            foreach (['draft_rescue_found', 'draft_rescue_restore', 'draft_rescue_discard'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $writing, $code . '/writing.php ' . $key);
            }
        }
        $countsCore = [];
        $countsWriting = [];
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $countsCore[] = preg_match_all("/^\s*'[^']+'\s*=>/m", (string)file_get_contents($this->base . '/lang/' . $code . '/core.php'));
            $countsWriting[] = preg_match_all("/^\s*'[^']+'\s*=>/m", (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php'));
        }
        $this->assertSame([$countsCore[0], $countsCore[0], $countsCore[0], $countsCore[0]], $countsCore, 'core parity');
        $this->assertSame([$countsWriting[0], $countsWriting[0], $countsWriting[0], $countsWriting[0]], $countsWriting, 'writing parity');
    }
}
