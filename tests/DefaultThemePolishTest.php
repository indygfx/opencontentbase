<?php

declare(strict_types=1);

namespace Tests\Polish;

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
 * Default-theme polish round 1 (prompt 23): slideshow pacing, button
 * spacing/width, story header actions menu, genre preselection bug,
 * select/dropdown styling, action-button consistency.
 */
final class DefaultThemePolishTest extends TestCase
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
        $path = sys_get_temp_dir() . '/cb_polish_' . uniqid() . '.sqlite';
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
        $this->admin = User::fromRow($this->db->one("SELECT id, username, role, language, theme FROM users WHERE id = 'a1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'My novel')");
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

    public function testSliderIntervalDoubledAndPausesOnPointer(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/story-slider.js');
        $this->assertMatchesRegularExpression('/const SLIDE_INTERVAL_MS = 16000;/', $js, 'interval constant = 2x the old 8000ms');
        $this->assertStringNotContainsString('setInterval(next, 8000)', $js);
        $this->assertStringContainsString("addEventListener('pointerenter'", $js);
        $this->assertStringContainsString("addEventListener('pointerleave'", $js);
        $this->assertStringContainsString('prefers-reduced-motion', $js, 'reduced motion disables auto-advance');
    }

    public function testMasterCssButtonSpacingAndMobileAutoWidth(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString('main button, main .button { margin-block: 16px; }', $css);
        $this->assertStringContainsString('.topbar button, form.inline button, .table-scroll button, .story-slider-nav button, .story-menu-btn, .editor-toolbar button { margin-block: 0; }', $css, 'chrome contexts keep compact spacing');
        $this->assertMatchesRegularExpression('/main button \+ button[^}]*margin-inline-start: 0\.5rem;/', $css, 'side-by-side buttons keep a horizontal gap');
        $this->assertMatchesRegularExpression('/button, \.button \{[^}]*width: var\(--button-width\);[^}]*min-height: 44px;/', $css, '44px target kept, width via variable');
        $phone = $this->mediaBlock($css, 480);
        $this->assertStringNotContainsString('--button-width', $phone, 'mobile: buttons are width:auto again (prompt 23)');
    }

    public function testMasterCssSelectRestyleAndStoryMenu(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertMatchesRegularExpression('/select \{[^}]*min-height: 44px;[^}]*appearance: none;[^}]*background-image: linear-gradient\(/s', $css, 'selects: 44px, appearance none, pure-CSS chevron');
        $this->assertStringContainsString('.story-header {', $css);
        $this->assertStringContainsString('.story-menu {', $css);
        $this->assertStringContainsString('box-shadow: var(--shadow);', $css);
        $this->assertStringContainsString('.story-menu__entry {', $css);
        $this->assertMatchesRegularExpression('/\.story-menu__entry \{[^}]*min-height: 44px;/s', $css, 'menu entries are 44px');
        $tablet = $this->mediaBlock($css, 768);
        $this->assertMatchesRegularExpression('/\.story-menu \{[^}]*position: fixed;[^}]*bottom: 0\.5rem;[^}]*max-height: 60vh;/s', $tablet, 'mobile: story menu is a bottom sheet like the editor dropdowns');
    }

    public function testAppJsDropdownHandler(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString('[data-dropdown]', $js);
        $this->assertStringContainsString('aria-expanded', $js);
        $this->assertStringContainsString("ev.key === 'Escape'", $js);
        $this->assertStringContainsString('stopPropagation', $js, 'outside-click close');
    }

    /**
     * The project template declares helper functions, so it can only be
     * rendered once per process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStoryDashboardHeaderMenuContainsExactlyTheThreeActions(): void
    {
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $body = $controller->show($this->admin, 'p1')->body();
        // header: title + accessible menu button
        $this->assertStringContainsString('class="story-header"', $body);
        $this->assertStringContainsString('class="story-menu-btn"', $body);
        $this->assertStringContainsString('aria-label="Story actions"', $body);
        $this->assertStringContainsString('aria-haspopup="true"', $body);
        $this->assertStringContainsString('class="story-menu"', $body);
        // dropdown contains exactly the three actions (owner sees all three)
        $this->assertSame(1, substr_count($body, '/writing/p1/graph"'));
        $this->assertSame(1, substr_count($body, '/writing/p1/export"'));
        $this->assertSame(1, substr_count($body, '/writing/p1/setup"'));
        $this->assertSame(3, substr_count($body, 'story-menu__entry'));
        // old dashboard locations are gone
        $this->assertStringNotContainsString('dashboard-actions', $body);
        $this->assertStringNotContainsString('actions-secondary', $body);
    }

    public function testSetupPagePreselectsSavedGenre(): void
    {
        $this->db->run("UPDATE projects SET genre = 'Fantasy' WHERE id = 'p1'");
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $body = $controller->setup($this->admin, 'p1')->body();
        $this->assertMatchesRegularExpression('/<option value="Fantasy"\s+selected/', $body, 'saved genre must be preselected');
        $this->assertStringNotContainsString('<option value="General" selected', $body);
    }

    public function testDetailPageActionsUseButtonClass(): void
    {
        $files = [
            'modules/Writing/templates/chapter.php',
            'modules/Writing/templates/synopsis.php',
            'modules/Writing/templates/character.php',
            'modules/Writing/templates/background.php',
            'modules/Writing/templates/_recent_revisions.php',
            'modules/Pages/templates/show.php',
        ];
        foreach ($files as $file) {
            $src = (string)file_get_contents($this->base . '/' . $file);
            $this->assertMatchesRegularExpression('/<a class="button" href="[^"]*(?:\/edit|\/revisions)"/', $src, $file . ': edit/history must render as .button');
        }
    }

    public function testStoryActionsKeyExistsInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            $this->assertStringContainsString("'story_actions'", $src, $code);
        }
    }
}
