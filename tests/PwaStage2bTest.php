<?php

declare(strict_types=1);

namespace Tests\Pwa;

use Core\Auth;
use Core\ContentRenderer;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\Translator;
use Core\User;
use Core\View;
use PHPUnit\Framework\TestCase;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * Prompt 29 - PWA stage 2b: offline writing dashboard (network-first + auto-warm
 * + silent refresh cadence), avatars SWR, per-story offline switch in the story
 * header dropdown, offline dashboard row muting. Editor bundle untouched.
 */
final class PwaStage2bTest extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $admin;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_pwa2b_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, new Auth($this->db)), new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['a1', 'rootuser', password_hash('adminpw123', PASSWORD_BCRYPT), 'admin']
        );
        $this->admin = User::fromRow($this->db->one("SELECT id, username, role, language, theme, avatar FROM users WHERE id = 'a1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'My novel')");
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->admin);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    public function testSwDashboardIsNetworkFirstAndAvatarsAreSwr(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v7'", $sw);
        $this->assertStringContainsString("const STORY_CACHE = 'offline-stories-v1';", $sw);
        $this->assertStringContainsString("key !== STORY_CACHE", $sw, 'story cache survives VERSION bumps');
        // dashboard: network-first with cached fallback (into the story cache)
        $this->assertMatchesRegularExpression('/if \(path === \'\\/writing\'\) \{.*?fetch\(req\).*?cache\.put\(req, resp\.clone\(\)\).*?cache\.match\(req\).*?caches\.match\(OFFLINE_URL\)/s', $sw);
        // avatars: SWR into the story cache
        $this->assertMatchesRegularExpression("/if \(path\.indexOf\('\\/avatars\/'\) === 0\) \{.*?cache\.match\(req\).*?cache\.put\(req, resp\.clone\(\)\)/s", $sw);
        // per-story pages: SWR only on cache hit, otherwise network-only + offline page
        $this->assertMatchesRegularExpression('/if \(path\.indexOf\(WRITING_PREFIX\) === 0\) \{.*?if \(hit\).*?return hit;.*?caches\.match\(OFFLINE_URL\)/s', $sw);
        // non-GET still never cached and still handled first
        $this->assertLessThan(strpos($sw, "path === '/writing'"), strpos($sw, "req.method !== 'GET'"));
    }

    public function testAppJsAutoWarmTimersAndSwitchAndOfflineRows(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        // auto-warm on controller ready: dashboard URL + scraped avatar imgs
        $this->assertStringContainsString("precacheUrls(['/writing'].concat(avatarUrls()))", $js);
        $this->assertStringContainsString('img[src^="/avatars/"]', $js);
        $this->assertStringContainsString('navigator.serviceWorker.ready', $js);
        // cadence: 60 min timer, 12 h per-story TTL
        $this->assertStringContainsString('var REFRESH_MS = 60 * 60 * 1000;', $js);
        $this->assertStringContainsString('var STORY_TTL_MS = 12 * 60 * 60 * 1000;', $js);
        $this->assertStringContainsString('setInterval(', $js);
        $this->assertStringContainsString('now - entry.ts > STORY_TTL_MS', $js);
        $this->assertStringContainsString('ts: Date.now()', $js, 'timestamp stored per story in the index');
        // switch: no menu close, no navigation, busy, revert on failure
        $this->assertStringContainsString('input[role="switch"]', $js);
        $this->assertStringContainsString('ev.stopPropagation()', $js);
        // pinned regression guard: the switch row must NOT preventDefault on
        // click - that cancels the checkbox's default toggle (no change event)
        $this->assertStringContainsString("row.addEventListener('click', function (ev) { ev.stopPropagation(); });", $js);
        $this->assertStringNotContainsString("row.addEventListener('click', function (ev) { ev.preventDefault()", $js);
        $this->assertStringContainsString('input.disabled = true', $js);
        // dashboard offline rows
        $this->assertStringContainsString("window.location.pathname !== '/writing'", $js);
        $this->assertStringContainsString("'online'", $js);
        $this->assertStringContainsString("'offline'", $js);
        $this->assertStringContainsString("classList.toggle('is-offline'", $js);
        $this->assertStringContainsString("classList.toggle('is-online-only'", $js);
        $this->assertStringContainsString("aria-disabled", $js);
        $this->assertStringContainsString("tr[data-project-id]", $js);
        // old dashboard button is gone
        $this->assertStringNotContainsString('offline-toggle', $js);
    }

    /**
     * project.php declares helper functions - render at most once per process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStoryDropdownHasSwitchBelowSetupAndNoOldButton(): void
    {
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()));
        $body = $controller->show($this->admin, 'p1')->body();
        $this->assertStringNotContainsString('offline-toggle', $body, 'old dashboard button removed');
        $this->assertStringContainsString('story-menu__switch', $body);
        $this->assertStringContainsString('role="switch"', $body);
        $this->assertStringContainsString('Story offline', $body, 'i18n label');
        $setupPos = strpos($body, '/setup"');
        $switchPos = strpos($body, 'story-menu__switch');
        $this->assertNotFalse($setupPos);
        $this->assertNotFalse($switchPos);
        $this->assertGreaterThan($setupPos, $switchPos, 'switch sits BELOW Novel Setup in the dropdown');
    }

    public function testIndexRowsCarryOfflineMarkers(): void
    {
        $src = (string)file_get_contents($this->base . '/modules/Writing/templates/index.php');
        $this->assertStringContainsString('data-project-id="<?= e($p[\'id\']) ?>"', $src);
        $this->assertStringContainsString('data-offline-hint="<?= e(t(\'writing\', \'offline_online_only\')) ?>"', $src);
    }

    public function testCssSwitchAndOfflineRulesUseVariablesOnly(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString('.offline-switch:checked { background: var(--accent); }', $css);
        $this->assertStringContainsString('body.is-offline tr.is-online-only a { opacity: 0.45; pointer-events: none; }', $css);
        $this->assertStringContainsString("attr(data-offline-hint)", $css);
        $rootEnd = strpos($css, '}', (int)strpos($css, ':root'));
        $outside = substr($css, 0, (int)strpos($css, ':root')) . substr($css, (int)$rootEnd + 1);
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside), 'whitelist: no color literals outside :root');
    }

    public function testI18nParityForNewKeys(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            foreach (['offline_story_toggle', 'offline_online_only'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . ' missing ' . $key);
            }
        }
    }
}
