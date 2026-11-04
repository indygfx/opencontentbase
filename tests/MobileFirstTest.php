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
 * Mobile-first phases 1+2 (docs/mobile-audit.md): table-scroll wrappers in
 * rendered pages, graph scroll wrapper + hint, both media-query blocks and the
 * touch-target rules in master.css (phase 1: F1/F2/F3/F5/F6/F11); phase 2
 * (F4/F7/F12/F13/F15/F16) covers the breakpoint-independent overflow-safety
 * base rules and the 768 px overrides for selects, users-table action cells,
 * progress labels and the dashboard action groups.
 */
final class MobileFirstTest extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;
    private View $view;
    private User $admin;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
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
        $this->db->run(
            "INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('s1', 'p1', 's-one', 'Outline')"
        );
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->csrf, $this->view, $this->admin);
        $_POST = [];
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
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

    public function testMasterCssPhase2BaseRulesAreBreakpointIndependent(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $base = substr($css, 0, (int)strpos($css, '@media'));
        // F13: rendered Markdown cannot overflow the reading column on any viewport
        $this->assertStringContainsString('.content img, .content pre, .content table { max-width: 100%; }', $base);
        $this->assertStringContainsString('.content pre { overflow-x: auto; }', $base);
        // F16: long words (de/fr compounds, flash messages) wrap instead of overflowing
        $this->assertStringContainsString('main, .card, .notice, .badge { overflow-wrap: break-word; }', $base);
        // F10: textarea fallback stays full-width (positive audit finding, no change needed)
        $this->assertStringContainsString('input, textarea { width: 100%;', $base);
        // F4: the secondary-actions label only appears below 768 px - desktop unchanged
        $this->assertStringContainsString('.actions-secondary-label { display: none; }', $base);
        $this->assertStringNotContainsString('select { width: 100%', $base);
    }

    public function testMasterCssPhase2TabletOverrides(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $tablet = $this->mediaBlock($css, 768);
        // F7: selects render full-width below 768 px
        $this->assertStringContainsString('select { width: 100%; max-width: 100%; box-sizing: border-box; }', $tablet);
        // F12: inline action forms inside scrollable tables stack vertically
        $this->assertStringContainsString('.table-scroll form.inline { display: block;', $tablet);
        $this->assertStringContainsString('.table-scroll details { margin-bottom: 0.5rem; }', $tablet);
        // F15: progress label wraps below the bar instead of squeezing it
        $this->assertStringContainsString('.progress { flex-wrap: wrap; }', $tablet);
        $this->assertStringContainsString('.progress-label { flex: 1 1 100%; }', $tablet);
        // F4: labeled secondary action group, full-width buttons below 768 px
        $this->assertStringContainsString('.actions-secondary-label { display: block;', $tablet);
        $this->assertStringContainsString('.dashboard-actions .button { width: 100%; }', $tablet);
    }

    public function testMoreActionsKeyExistsInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $file = $this->base . '/lang/' . $code . '/writing.php';
            $this->assertStringContainsString("'more_actions'", (string)file_get_contents($file), $file);
        }
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

    /**
     * The project template declares helper functions, so it can only be
     * rendered once per process - this single test covers the dashboard
     * (project.php) plus the users list (users.php).
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRenderedStoryDashboardAndUsersListWrapTables(): void
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
        // F4: dashboard actions are grouped; secondary actions carry a labeled group
        $this->assertStringContainsString('class="dashboard-actions"', $body);
        $this->assertStringContainsString('class="actions-secondary"', $body);
        $this->assertStringContainsString('class="actions-secondary-label">More actions<', $body);
        $users = new UserAdminController($this->db, $this->auth, $this->view, $this->csrf);
        $usersBody = $users->index($this->admin)->body();
        $this->assertStringContainsString('<div class="table-scroll"><table>', $usersBody);
        // F12: the users table keeps its inline action forms inside the scroll wrapper
        $this->assertStringContainsString('<form class="inline"', $usersBody);
    }

    public function testRenderedDashboardWrapsStoryListTable(): void
    {
        $writing = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $this->assertStringContainsString('<div class="table-scroll"><table>', $writing->index($this->admin)->body());
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

    public function testEditorCssHasExactlyOneMobileBlockWithCollapseRules(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/editor.css');
        $this->assertSame(1, substr_count($css, '@media (max-width: 768px)'), 'editor.css must have exactly one 768px block');
        $block = $this->mediaBlock($css, 768);
        // F8 collapse pattern: toolbar wraps, collapsed shows only the first row
        $this->assertMatchesRegularExpression('/\.editor-toolbar \{[^}]*flex-wrap: wrap;[^}]*overflow-x: hidden;/s', $block);
        $this->assertMatchesRegularExpression('/\.editor-toolbar\.is-collapsed \{[^}]*max-height: 44px;[^}]*overflow: hidden;[^}]*padding-right: 3\.4rem;/s', $block);
        // F8: chevron toggle anchored on the toolbar's right edge, 44px target, never full-width
        $this->assertMatchesRegularExpression('/\.editor-toolbar-toggle \{[^}]*position: absolute;[^}]*right: 0\.25rem;[^}]*width: auto;[^}]*min-width: 44px;[^}]*min-height: 44px;/s', $block);
        // toggle is display: none by default (outside any media query) and only
        // displayed inside the 768px block, where [hidden] still wins
        $base = substr($css, 0, (int)strpos($css, '@media'));
        $this->assertMatchesRegularExpression('/\.editor-toolbar-toggle \{[^}]*display: none;/s', $base);
        $this->assertMatchesRegularExpression('/\.editor-toolbar-toggle \{[^}]*display: inline-flex;/s', $block);
        $this->assertMatchesRegularExpression('/\.editor-toolbar-toggle\[hidden\] \{[^}]*display: none;/s', $block);
        // F8: buttons and menus must not shrink when wrapping
        $this->assertMatchesRegularExpression('/\.editor-toolbar-btn, \.editor-menu \{[^}]*flex: 0 0 auto;/s', $block);
        // F8: 44px touch targets on toolbar buttons
        $this->assertMatchesRegularExpression('/\.editor-toolbar-btn \{[^}]*min-height: 44px;[^}]*min-width: 44px;/s', $block);
        // F8: dense separator, counter inline at the end
        $this->assertMatchesRegularExpression('/\.editor-toolbar-sep \{[^}]*margin: 0;/s', $block);
        $this->assertMatchesRegularExpression('/\.editor-counter \{[^}]*margin-left: 0;[^}]*flex: 0 0 auto;/s', $block);
        // F8: dropdowns become fixed bottom sheets
        $this->assertMatchesRegularExpression('/\.editor-menu__dropdown\.is-open \{[^}]*position: fixed;[^}]*bottom: 0\.5rem;[^}]*max-height: 60vh;[^}]*overflow-y: auto;/s', $block);
        // F8: menu entries full width and 44px
        $this->assertMatchesRegularExpression('/\.editor-menu__entry \{[^}]*width: 100%;[^}]*min-height: 44px;[^}]*font-size: 1rem;/s', $block);
    }

    public function testToolbarToggleIsNeverFullWidthEvenBelow480(): void
    {
        // master.css sets `button { width: 100% }` inside its 480px block (element
        // selector, specificity 0-0-1); editor.css's `.editor-toolbar-toggle
        // { width: auto }` (class selector, 0-1-0) must override it in every block.
        $master = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertMatchesRegularExpression('/button, \.button \{[^}]*width: 100%;/s', $this->mediaBlock($master, 480));
        $css = (string)file_get_contents($this->base . '/public/assets/css/editor.css');
        $block768 = $this->mediaBlock($css, 768);
        $this->assertMatchesRegularExpression('/\.editor-toolbar-toggle \{[^}]*width: auto;/s', $block768);
    }

    public function testEditorCssHasNoObsoleteScrollAffordanceLeft(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/editor.css');
        // The scrollable-row approach is superseded by wrap+collapse: no fade, no scrollbar styling.
        $this->assertStringNotContainsString('.editor-wrap::after', $css);
        $this->assertStringNotContainsString('scrollbar-width', $css);
        $this->assertStringNotContainsString('overscroll-behavior', $css);
        $this->assertStringNotContainsString('-webkit-overflow-scrolling', $css);
        // No horizontal scrolling in the mobile block (the desktop
        // .editor-prose pre code-block scroll outside @media stays).
        $mobileBlocks = '';
        if (preg_match_all('/@media[^{]*\{(?:[^{}]|\{[^{}]*\})*\}/s', $css, $m) === 1 || !empty($m[0])) {
            $mobileBlocks = implode("\n", $m[0]);
        }
        $this->assertStringNotContainsString('overflow-x: auto', $mobileBlocks);
        // The wrap anchor is not needed anymore (toggle anchors on the sticky toolbar).
        $this->assertStringNotContainsString('.editor-wrap { position: relative; }', $css);
    }

    public function testAppJsImplementsToolbarCollapse(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        // single breakpoint source
        $this->assertStringContainsString("matchMedia('(max-width: 768px)')", $js);
        // idempotent setup guard
        $this->assertStringContainsString('collapseReady', $js);
        // toggle button creation with a11y attributes
        $this->assertStringContainsString("className = 'editor-toolbar-toggle'", $js);
        $this->assertStringContainsString('aria-expanded', $js);
        // measured overflow detection (collapse first, then compare heights)
        $this->assertStringContainsString('scrollHeight > bar.clientHeight', $js);
        // sticky collapse state: the tap stores the choice and update() re-applies
        // it, so resize/breakpoint changes never re-collapse an expanded toolbar
        $this->assertStringContainsString("bar.dataset.collapsed = collapsed ? '1' : '0'", $js);
        $this->assertStringContainsString("bar.classList.toggle('is-collapsed', collapsed)", $js);
        // runs on breakpoint change, resize, DOMContentLoaded and load
        $this->assertStringContainsString("mq.addEventListener('change'", $js);
        $this->assertStringContainsString("window.addEventListener('resize'", $js);
        $this->assertStringContainsString("document.addEventListener('DOMContentLoaded'", $js);
        $this->assertStringContainsString("window.addEventListener('load'", $js);
    }

    public function testEditorBundleSourcesUntouchedByMobileFix(): void
    {
        // The mobile toolbar fix is CSS-only: no inline script/style in the
        // edit templates and the editor bundle is referenced as a file.
        foreach (glob($this->base . '/modules/*/templates/*_edit.php') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            $this->assertSame(0, (int)preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $source), $file);
        }
        $this->assertFileExists($this->base . '/public/assets/js/editor.js');
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
