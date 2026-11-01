<?php
declare(strict_types=1);

namespace Tests\Mobile;

use Core\Auth;
use Core\ContentRenderer;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\Translator;
use Core\User;
use Core\UserAdminController;
use Core\View;
use PHPUnit\Framework\TestCase;
use Writing\GraphController;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * Mobile-first phase 1 (docs/mobile-audit.md F1/F2/F3/F5/F6/F11): table-scroll
 * wrappers in rendered pages, graph scroll wrapper + hint, both media-query
 * blocks and the touch-target rules in master.css.
 */
final class MobileFirstTest extends TestCase
{
    private string $base;
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;
    private View $view;
    private User $admin;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_mobile_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->csrf = new Csrf($this->db, $this->auth);
        $this->view = new View($this->base);
        $module = new WritingModule($this->db, $this->view, $this->csrf, new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->db->run(
            "INSERT INTO users (id, username, password, role) VALUES ('a1', 'rootuser', ?, 'admin')",
            [password_hash('adminpw123', PASSWORD_BCRYPT)]
        );
        $this->admin = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'a1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'My novel')");
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->csrf, $this->view, $this->admin);
        $_POST = [];
    }

    public function testMasterCssHasBothMediaQueryBlocks(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString('@media (max-width: 768px)', $css);
        $this->assertStringContainsString('@media (max-width: 480px)', $css);
    }

    public function testMasterCssDefinesTableScrollGraphHintAndTouchTargets(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString('.table-scroll { overflow-x: auto;', $css);
        $this->assertStringContainsString('.graph-scroll-hint { display: none; }', $css);
        $this->assertMatchesRegularExpression('/button, \.button \{[^}]*min-height: 44px/', $css);
        $this->assertMatchesRegularExpression('/\.topbar nav a \{[^}]*min-height: 44px/', $css);
        $this->assertMatchesRegularExpression('/\.story-slider-nav button \{[^}]*min-height: 44px/', $css);
    }

    public function testMasterCssKeepsGraphMinWidthAndSliderUnclippedBelow768(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $tablet = $this->mediaBlock($css, 768);
        $this->assertStringContainsString('.relationship-graph { min-width: 900px; }', $tablet);
        $this->assertStringContainsString('.graph-wrap { overflow-x: auto;', $tablet);
        $this->assertStringContainsString('.graph-scroll-hint { display: block; }', $tablet);
        $this->assertStringContainsString('.story-slider { padding: 1.25rem 1rem; overflow: visible; }', $tablet);
        $this->assertStringContainsString('.story-slides { min-height: 0; }', $tablet);
    }

    public function testEveryTemplateTableIsWrappedInTableScroll(): void
    {
        $files = array_merge(
            glob($this->base . '/templates/*.php') ?: [],
            glob($this->base . '/modules/*/templates/*.php') ?: []
        );
        $tables = 0;
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            $tables += substr_count($source, '<table>');
            $this->assertSame(
                substr_count($source, '<table>'),
                substr_count($source, '<div class="table-scroll"><table>'),
                $file . ': every <table> must sit inside a .table-scroll wrapper'
            );
        }
        $this->assertGreaterThanOrEqual(12, $tables, 'audit expects at least 12 wrapped tables');
    }

    public function testRenderedStoryDashboardWrapsTables(): void
    {
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $body = $controller->show($this->admin, 'p1')->body();
        $this->assertStringContainsString('<div class="table-scroll"><table>', $body);
    }

    public function testRenderedDashboardAndUsersPagesWrapTables(): void
    {
        $writing = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $this->assertStringContainsString('<div class="table-scroll"><table>', $writing->index($this->admin)->body());
        $users = new UserAdminController($this->db, $this->auth, $this->view, $this->csrf);
        $this->assertStringContainsString('<div class="table-scroll"><table>', $users->index($this->admin)->body());
    }

    public function testGraphPageContainsScrollHintAndSvg(): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c1', 'character', 'jakob', 'a1')"
        );
        $this->db->run("INSERT INTO characters (id, project_id, slug, image_path) VALUES ('c1', 'p1', 'jakob', '')");
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [Auth::uuid4(), 'c1', 'Jakob', '', 'a1']
        );
        $access = new ProjectAccess($this->db);
        $controller = new GraphController(
            $this->db,
            $this->view,
            $this->csrf,
            $access,
            new \Writing\CharacterMentions($this->db),
            new \Writing\RelationshipController($this->db, $this->view, $this->csrf, $access)
        );
        $body = $controller->show($this->admin, 'p1')->body();
        $this->assertStringContainsString('<svg', $body);
        $this->assertStringContainsString('class="muted graph-scroll-hint"', $body);
        $this->assertStringContainsString(t('writing', 'graph_scroll_hint'), $body);
        $this->assertStringContainsString('class="graph-wrap"', $body);
    }

    public function testGraphScrollHintKeyExistsInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $file = $this->base . '/lang/' . $code . '/writing.php';
            $this->assertStringContainsString("'graph_scroll_hint'", (string)file_get_contents($file), $file);
        }
    }

    private function mediaBlock(string $css, int $maxWidth): string
    {
        $needle = '@media (max-width: ' . $maxWidth . 'px)';
        $start = strpos($css, $needle);
        $this->assertNotFalse($start, $needle . ' missing');
        $open = strpos($css, '{', $start);
        $depth = 0;
        for ($i = $open, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $open, $i - $open + 1);
                }
            }
        }
        $this->fail('unbalanced braces in ' . $needle);
    }
}
