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
 * Prompt 27: Cancel button in all edit views (quiet variant, GET link to the
 * canonical back target) + body class "editing" marker on edit views only +
 * mobile full-bleed CSS scoped to body.editing + SW VERSION bumped.
 */
final class EditViewsTest extends TestCase
{
    private const EDIT_TEMPLATES = [
        'modules/Writing/templates/chapter_edit.php',
        'modules/Writing/templates/synopsis_edit.php',
        'modules/Writing/templates/character_edit.php',
        'modules/Writing/templates/background_edit.php',
        'modules/Writing/templates/outline_edit.php',
        'modules/Writing/templates/relationship_form.php',
        'modules/Writing/templates/setup.php',
        'modules/Writing/templates/import.php',
        'modules/Pages/templates/edit.php',
    ];

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
        $path = sys_get_temp_dir() . '/cb_edit_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['a1', 'rootuser', password_hash('adminpw123', PASSWORD_BCRYPT), 'admin']
        );
        $this->admin = User::fromRow($this->db->one("SELECT id, username, role, language, theme, avatar FROM users WHERE id = 'a1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'My novel')");
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->admin);
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
        $this->fail('unbalanced braces');
    }

    public function testLayoutSupportsOptionalBodyClass(): void
    {
        $view = new View($this->base);
        $csrf = new Csrf($this->db, $this->auth);
        $with = $view->render('templates/layout.php', ['title' => 'T', 'user' => $this->admin, 'csrf' => $csrf, 'content' => '<p>x</p>', 'bodyClass' => 'editing']);
        $this->assertStringContainsString('<body class="editing"', $with);
        $without = $view->render('templates/layout.php', ['title' => 'T', 'user' => $this->admin, 'csrf' => $csrf, 'content' => '<p>x</p>']);
        $this->assertStringContainsString('<body data-update-available=', $without);
        $this->assertStringNotContainsString('class="editing"', $without);
    }

    public function testEveryEditTemplateHasCancelAfterSave(): void
    {
        foreach (self::EDIT_TEMPLATES as $tpl) {
            $src = (string)file_get_contents($this->base . '/' . $tpl);
            $this->assertStringContainsString('button button-secondary', $src, $tpl . ': quiet cancel variant');
            $this->assertStringContainsString("t('core', 'cancel')", $src, $tpl . ': cancel label key');
            $savePos = strpos($src, 'type="submit"');
            $cancelPos = strpos($src, 'button-secondary');
            $this->assertNotFalse($savePos, $tpl . ': save submit');
            $this->assertNotFalse($cancelPos, $tpl . ': cancel link');
            $this->assertGreaterThan($savePos, $cancelPos, $tpl . ': Save renders before Cancel');
            $this->assertMatchesRegularExpression('/<a class="button button-secondary" href="[^"]+">/', $src, $tpl . ': cancel is a plain GET link');
        }
    }

    public function testPageHelpersDeriveEditingMarkerOnlyForEditTemplates(): void
    {
        $helpers = [
            'modules/Pages/src/PagesController.php',
            'modules/Writing/src/BackgroundController.php',
            'modules/Writing/src/ChapterController.php',
            'modules/Writing/src/ChapterSynopsisController.php',
            'modules/Writing/src/CharacterController.php',
            'modules/Writing/src/GraphController.php',
            'modules/Writing/src/RelationshipController.php',
            'modules/Writing/src/RevisionController.php',
            'modules/Writing/src/WritingController.php',
        ];
        foreach ($helpers as $f) {
            $src = (string)file_get_contents($this->base . '/' . $f);
            $this->assertStringContainsString("preg_match('#(edit\\.php|setup\\.php|relationship_form\\.php|import\\.php)\$#', \$innerTemplate)", $src, $f . ': marker derivation');
            $this->assertStringContainsString("'bodyClass' => \$bodyClass,", $src, $f . ': marker passed to layout');
        }
        $import = (string)file_get_contents($this->base . '/modules/Writing/src/StoryImportController.php');
        $this->assertStringContainsString("'bodyClass' => 'editing'", $import, 'import view is always edit mode');
    }

    public function testSetupRenderHasEditingMarkerCancelAndOrder(): void
    {
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()));
        $body = $controller->setup($this->admin, 'p1')->body();
        $this->assertStringContainsString('<body class="editing"', $body, 'edit view sets the marker');
        $this->assertStringContainsString('button button-secondary', $body);
        $this->assertStringContainsString('href="/writing/p1"', $body, 'cancel leads back to the project dashboard');
        $savePos = strpos($body, 'type="submit"');
        $cancelPos = strpos($body, 'button-secondary');
        $this->assertGreaterThan($savePos, $cancelPos, 'Save before Cancel');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testDashboardRenderHasNoEditingMarker(): void
    {
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()));
        $body = $controller->show($this->admin, 'p1')->body();
        $this->assertStringNotContainsString('class="editing"', $body, 'read views never set the marker');
    }

    public function testFullBleedRulesExistOnlyInside768BlockScopedToEditing(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $base = substr($css, 0, (int)strpos($css, '@media'));
        $block = $this->mediaBlock($css, 768);
        // scoped rules only inside the mobile block
        foreach ([
            'body.editing main { padding: 0; margin: 1rem auto; }',
            'body.editing .card, body.editing .content { padding: 0; }',
            'body.editing .card label { display: block; margin: 0 16px; }',
            'body.editing .card input, body.editing .card select, body.editing .card textarea { margin: 0 16px; width: calc(100% - 32px); box-sizing: border-box; }',
            'body.editing .card form button[type="submit"] { margin-inline-start: 16px; }',
        ] as $rule) {
            $this->assertStringContainsString($rule, $block, 'mobile block: ' . $rule);
        }
        $this->assertStringNotContainsString('body.editing', $base, 'no editing-scoped rules outside the media block');
        // the global rules stay exactly as before (pixel-identical read views)
        $this->assertStringContainsString('main { max-width: 52rem; margin: 1.5rem auto; padding: 0 1rem; }', $base);
        $this->assertStringContainsString('.card { background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 1.5rem; }', $base);
        $this->assertStringContainsString('.content { background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 1.5rem; }', $base);
        // quiet button variant exists in base (inert without usage)
        $this->assertStringContainsString('.button-secondary { background: transparent; border: 1px solid var(--line); color: var(--ink); }', $base);
        // whitelist rule stays green (margins only, no color literals added)
        $rootEnd = strpos($css, '}', (int)strpos($css, ':root'));
        $outside = substr($css, 0, (int)strpos($css, ':root')) . substr($css, (int)$rootEnd + 1);
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside));
    }

    public function testSwVersionBumpedInSameChange(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertMatchesRegularExpression("/const VERSION = 'v[4-9]'/", $sw, 'prompt 27b bumps master.css+editor.css -> v4+');
    }

    public function testPrompt27bSpacingRulesScopedToEditingInMobileBlockOnly(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $base = substr($css, 0, (int)strpos($css, '@media'));
        $block = $this->mediaBlock($css, 768);
        // the flagged element groups carry margin-inline: 16px, scoped + mobile-only
        $rule = 'body.editing .card > h1, body.editing .card > h2, body.editing .card > .muted,'
            . ' body.editing .card > .error, body.editing .card > .success,'
            . ' body.editing .card > .outline-box, body.editing .card > .table-scroll';
        $this->assertStringContainsString($rule, $block);
        $this->assertMatchesRegularExpression('/margin-inline: 16px;/', $block);
        $this->assertStringNotContainsString('body.editing', $base, 'no editing rules outside the mobile block');
        // writing surface stays full-bleed: no margin rule on .editor-wrap
        $this->assertStringNotContainsString('.editor-wrap {', $block, 'editor surface must not get margins');
        // un-scoped base rules unchanged
        $this->assertStringContainsString('.card { background: var(--surface); border: 1px solid var(--line); border-radius: 6px; padding: 1.5rem; }', $base);
        $this->assertStringContainsString('.outline-box { background: var(--bg);', $base);
    }

    public function testEditorProseComfortTypographyInBaseRulesNotMediaScoped(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/editor.css');
        $base = substr($css, 0, (int)strpos($css, '@media'));
        $this->assertMatchesRegularExpression(
            '/\.editor-prose \{[^}]*outline: none;[^}]*min-height: 9rem;[^}]*line-height: 1\.8;[^}]*font-size: 120%;[^}]*\}/s',
            $base,
            'prose comfort typography in base rules (desktop AND mobile)'
        );
        // toolbar chrome untouched: base toolbar rule still sticky, no new margin rule
        $this->assertMatchesRegularExpression('/\.editor-toolbar \{[^}]*position: sticky;[^}]*top: 0;/s', $base);
" . substr($css, (int)strpos($css, '@media')) === '' ? '' : '');
        $toolbarBase = substr($base, (int)strpos($base, '.editor-toolbar {'), 200);
        $this->assertStringNotContainsString('margin', $toolbarBase, 'no new margin on toolbar chrome');
        // whitelist still green
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $css));
    }

    public function testCancelKeyInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/core.php');
            $this->assertStringContainsString("'cancel' =>", $src, $code . '/core.php missing cancel');
        }
    }
}
