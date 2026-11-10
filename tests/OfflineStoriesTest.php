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
 * Prompt 28 - PWA stage 2: per-story offline precache. Server side:
 * GET /writing/{id}/offline-manifest.json (auth-guarded, JSON with all read
 * pages + media URLs). Client side: sw.js message handlers + dedicated
 * 'offline-stories-v1' cache, app.js toggle with localStorage index,
 * offline.html as the offline library.
 */
final class OfflineStoriesTest extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $owner;
    private User $member;
    private User $stranger;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_offline_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, new Auth($this->db)), new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->auth = new Auth($this->db);
        foreach (['a1' => 'alice', 'm1' => 'bob', 's1' => 'mallory'] as $id => $name) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), $id === 'a1' ? 'admin' : 'user']
            );
        }
        $this->owner = $this->user('a1');
        $this->member = $this->user('m1');
        $this->stranger = $this->user('s1');
        // fixture story: project + chapter + synopsis + character (with portrait) + background + cover + member
        $this->db->run("INSERT INTO projects (id, owner_id, title, cover) VALUES ('p1', 'a1', 'My novel', 'p1.png')");
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'm1', 'member')");
        $this->db->run("INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('s1', 'p1', 'outline', 'Outline')");
        $this->db->run("INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c1', 'p1', 's1', 'prolog')");
        $this->db->run("INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c2', 'p1', 's1', 'drachenhord')");
        $this->db->run("INSERT INTO characters (id, project_id, slug, title, image_path) VALUES ('ch1', 'p1', 'liestre', 'Liestre', 'ch1.png')");
        $this->db->run("INSERT INTO backgrounds (id, project_id, slug, title) VALUES ('b1', 'p1', 'karma', 'KarmaCorp')");
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->owner, $this->member, $this->stranger);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role, language, theme, avatar FROM users WHERE id = ?", [$id]));
    }

    private function controller(): WritingController
    {
        return new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()));
    }

    public function testManifestRouteIsRegistered(): void
    {
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ContentRenderer($this->db, new ModuleRegistry()));
        $found = false;
        foreach ($module->routes(new \Core\Router()) as $route) {
            if ($route['pattern'] === '/writing/{id}/offline-manifest.json' && $route['method'] === 'GET') {
                $found = true;
            }
        }
        $this->assertTrue($found, 'offline-manifest.json route must exist');
    }

    public function testManifestContainsAllReadPagesAndMedia(): void
    {
        $response = $this->controller()->offlineManifest($this->member, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $data = json_decode($response->body(), true);
        $this->assertSame('p1', $data['project'], 'projects have no slug column - the id is the identifier');
        $this->assertSame('My novel', $data['title']);
        $urls = $data['urls'];
        foreach ([
            '/writing/p1',
            '/writing/p1/graph',
            '/writing/p1/chapters/prolog',
            '/writing/p1/chapters/drachenhord',
            '/writing/p1/synopses/outline',
            '/writing/p1/characters/liestre',
            '/writing/p1/backgrounds/karma',
            '/writing/p1/characters/liestre/image',
            '/writing/p1/cover',
            '/avatars/a1',
            '/avatars/m1',
        ] as $url) {
            $this->assertContains($url, $urls, 'manifest must contain ' . $url);
        }
        // no edit pages, no assets, no duplicates
        foreach ($urls as $u) {
            $this->assertStringNotContainsString('/edit', $u);
            $this->assertStringNotContainsString('/assets/', $u);
        }
        $this->assertSame($urls, array_values(array_unique($urls)), 'urls unique');
    }

    public function testManifestIsAuthGuarded(): void
    {
        $this->assertSame(404, $this->controller()->offlineManifest($this->stranger, 'p1')->status(), 'non-member gets 404');
        $this->assertSame(404, $this->controller()->offlineManifest($this->owner, 'nope')->status(), 'unknown project 404');
        $this->assertSame(200, $this->controller()->offlineManifest($this->owner, 'p1')->status(), 'owner ok');
    }

    public function testSwJsHasStoryCacheMessageHandlersAndV5(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v5'", $sw);
        $this->assertStringContainsString("const STORY_CACHE = 'offline-stories-v1';", $sw);
        $this->assertStringContainsString("data.type === 'PRECACHE_STORY'", $sw);
        $this->assertStringContainsString("data.type === 'REMOVE_STORY'", $sw);
        $this->assertStringContainsString("cache: 'reload'", $sw);
        $this->assertStringContainsString("credentials: 'include'", $sw);
        $this->assertStringContainsString("PRECACHE_DONE", $sw);
        $this->assertStringContainsString("REMOVE_DONE", $sw);
        $this->assertStringContainsString("key !== STORY_CACHE", $sw, 'story cache survives VERSION bumps on activate');
        // non-GET still never cached (branch still first)
        $this->assertLessThan(strpos($sw, "path.indexOf(WRITING_PREFIX)"), strpos($sw, "req.method !== 'GET'"));
        // story-cache hits get SWR treatment, misses stay network-only with offline fallback
        $this->assertStringContainsString("cache.match(req)", $sw);
        $this->assertStringContainsString("caches.match(OFFLINE_URL)", $sw);
    }

    public function testAppJsOfflineToggleLogic(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString("var INDEX_KEY = 'offline-stories';", $js);
        $this->assertStringContainsString('offline-manifest.json', $js);
        $this->assertStringContainsString('navigator.storage.estimate', $js);
        $this->assertStringContainsString('PRECACHE_STORY', $js);
        $this->assertStringContainsString('REMOVE_STORY', $js);
        $this->assertStringContainsString('navigator.serviceWorker.controller === null', $js, 'hidden without controller (HTTP dev)');
        $this->assertStringContainsString('btn.disabled = on', $js, 'busy state');
        $this->assertStringContainsString('localStorage.setItem(INDEX_KEY', $js);
        $this->assertStringContainsString('app-toast', $js);
    }

    public function testProjectDashboardRendersHiddenToggleButton(): void
    {
        $src = (string)file_get_contents($this->base . '/modules/Writing/templates/project.php');
        $this->assertStringContainsString('class="button offline-toggle" hidden', $src);
        $this->assertStringContainsString('data-project=', $src);
        $this->assertStringContainsString("offline_make_available", $src);
    }

    public function testOfflineHtmlIsTheOfflineLibrary(): void
    {
        $html = (string)file_get_contents($this->base . '/public/offline.html');
        $this->assertStringContainsString('id="offline-library" hidden', $html, 'library section hidden by default');
        $this->assertStringContainsString('offline-library-list', $html);
        foreach (['en', 'de', 'es', 'fr'] as $lang) {
            $this->assertMatchesRegularExpression('/data-lang="' . $lang . '"/', $html, 'library title translated: ' . $lang);
        }
        $this->assertStringContainsString("localStorage.getItem('offline-stories')", $html);
        $this->assertStringContainsString("'REMOVE_STORY'", $html);
        $this->assertStringContainsString('section.hidden = true', $html, 'hidden when index empty');
        $this->assertStringContainsString('/assets/css/master.css', $html, 'still theme-inherited');
    }

    public function testI18nParityForOfflineKeys(): void
    {
        $keys = ['offline_make_available', 'offline_remove', 'offline_success', 'offline_failed', 'offline_busy', 'offline_library_title'];
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            foreach ($keys as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . '/writing.php missing ' . $key);
            }
        }
    }
}
