<?php

declare(strict_types=1);

namespace Tests\Editor;

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
use Writing\ChapterController;
use Writing\ChapterSynopsisController;
use Writing\ProjectAccess;
use Writing\WritingModule;

/**
 * Prompt 31 - editor polish: (1) undo/redo fix in the editor bundle,
 * (2) draft save via JSON branch on the existing chapter save endpoint,
 * (3) sticky save dock on scroll. SW VERSION v9.
 */
final class EditorPolish31Test extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $owner;
    private User $stranger;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_p31_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, new Auth($this->db)), new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->auth = new Auth($this->db);
        foreach (['a1' => 'alice', 's1' => 'mallory'] as $id => $name) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = $this->user('a1');
        $this->stranger = $this->user('s1');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'Novel')");
        $this->db->run("INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('s1', 'p1', 'outline', 'Outline')");
        $this->db->run("INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c1', 'p1', 's1', 'ch1')");
        $this->db->run("INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c1', 'chapter', 'ch1', 'a1')");
        $_POST = [];
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->owner, $this->stranger);
        $_POST = [];
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role, language, theme, avatar FROM users WHERE id = ?", [$id]));
    }

    private function chapterController(): ChapterController
    {
        $view = new View($this->base);
        $csrf = new Csrf($this->db, $this->auth);
        $access = new ProjectAccess($this->db);
        $renderer = new ContentRenderer($this->db, new ModuleRegistry());
        return new ChapterController($this->db, $view, $csrf, $access, $renderer, new ChapterSynopsisController($this->db, $view, $csrf, $access, $renderer));
    }

    // ---- Part 1: undo/redo wiring in source + rebuilt bundle ----

    public function testUndoRedoCommandsExistInSourceAndBundle(): void
    {
        $src = (string)file_get_contents($this->base . '/assets/editor/main.js');
        $this->assertStringContainsString('undo: (e) => e.chain().focus().undo().run()', $src);
        $this->assertStringContainsString('redo: (e) => e.chain().focus().redo().run()', $src);
        $bundle = (string)file_get_contents($this->base . '/public/assets/js/editor.js');
        $this->assertStringContainsString('focus().undo()', $bundle, 'rebuilt bundle must contain the undo command');
        $this->assertStringContainsString('focus().redo()', $bundle, 'rebuilt bundle must contain the redo command');
    }

    // ---- Part 2: JSON branch on the existing save endpoint ----

    public function testUpdateJsonBranchSuccess(): void
    {
        $_POST = ['synopsis_id' => 's1', 'slug' => 'ch1', 'body' => 'draft text', 'csrf_token' => 'x'];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $response = $this->chapterController()->update($this->owner, 'p1', 'ch1');
        $this->assertSame(200, $response->status());
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $this->assertNull($response->header('Location'), 'JSON branch must not redirect');
        $this->assertSame(['ok' => true], json_decode($response->body(), true));
        // the draft was actually persisted (existing save logic reused)
        $row = $this->db->one("SELECT body FROM content_revisions WHERE object_id = 'c1' ORDER BY created_at DESC LIMIT 1");
        $this->assertSame('draft text', $row['body']);
    }

    public function testUpdateJsonBranchValidationErrorIs422Json(): void
    {
        $_POST = ['synopsis_id' => 'nope', 'slug' => 'ch1', 'body' => 'x', 'csrf_token' => 'x'];
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ACCEPT'] = 'text/html';
        $response = $this->chapterController()->update($this->owner, 'p1', 'ch1');
        $this->assertSame(422, $response->status());
        $this->assertSame('application/json; charset=utf-8', $response->header('Content-Type'));
        $data = json_decode($response->body(), true);
        $this->assertFalse($data['ok']);
        $this->assertNotEmpty($data['errors']);
    }

    public function testUpdateHtmlBranchUnchanged(): void
    {
        $_POST = ['synopsis_id' => 's1', 'slug' => 'ch1', 'body' => 'full save', 'csrf_token' => 'x'];
        // no AJAX headers -> classic redirect
        $response = $this->chapterController()->update($this->owner, 'p1', 'ch1');
        $this->assertSame(302, $response->status());
        $this->assertSame('/writing/p1/chapters/ch1', $response->header('Location'));
    }

    public function testUpdateAuthGuardAppliesToJsonRequestsToo(): void
    {
        $_POST = ['synopsis_id' => 's1', 'slug' => 'ch1', 'body' => 'x', 'csrf_token' => 'x'];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertSame(404, $this->chapterController()->update($this->stranger, 'p1', 'ch1')->status());
    }

    // ---- Part 3: sticky save dock ----

    public function testDockExistsOnlyOnChapterEditPage(): void
    {
        $chapterEdit = (string)file_get_contents($this->base . '/modules/Writing/templates/chapter_edit.php');
        $this->assertStringContainsString('class="editor-save-dock"', $chapterEdit);
        $this->assertStringContainsString('id="chapter-form"', $chapterEdit);
        $this->assertSame(2, substr_count($chapterEdit, 'save-draft'), 'footer + dock draft buttons');
        $this->assertStringContainsString('type="submit" form="chapter-form"', $chapterEdit, 'dock Save submits the form normally');
        foreach (['synopsis_edit.php', 'character_edit.php', 'background_edit.php', 'outline_edit.php', 'setup.php', 'relationship_form.php', 'import.php'] as $tpl) {
            $src = (string)file_get_contents($this->base . '/modules/Writing/templates/' . $tpl);
            $this->assertStringNotContainsString('editor-save-dock', $src, $tpl . ' must not have the dock');
        }
        $pagesEdit = (string)file_get_contents($this->base . '/modules/Pages/templates/edit.php');
        $this->assertStringNotContainsString('editor-save-dock', $pagesEdit);
    }

    public function testAppJsDockScrollLogicAndDraftSave(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        // dock scroll handler: direction check, min delta, idle timeout, visibility via class
        $this->assertStringContainsString("var MIN_DELTA = 24;", $js);
        $this->assertStringContainsString("var IDLE_MS = 1200;", $js);
        $this->assertStringContainsString('delta > MIN_DELTA', $js, 'only downward scroll shows');
        $this->assertStringContainsString('setTimeout(hide, IDLE_MS)', $js);
        $this->assertStringContainsString("classList.add('is-visible')", $js);
        // draft save: fetch with JSON signal headers, no navigation
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
        $this->assertStringContainsString("'Accept': 'application/json'", $js);
        $this->assertStringContainsString('new FormData(form)', $js);
        $this->assertStringContainsString('ev.preventDefault()', $js);
    }

    public function testDockCssUsesVisibilityTransitionAndVariablesOnly(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertMatchesRegularExpression('/\\.editor-save-dock \\{[^}]*position: fixed;[^}]*env\\(safe-area-inset-bottom\\)[^}]*visibility: hidden;[^}]*transition: opacity 0\\.2s ease, visibility 0\\.2s ease;/s', $css);
        $this->assertStringContainsString('.editor-save-dock.is-visible { opacity: 1; visibility: visible; }', $css);
        $rootEnd = strpos($css, '}', (int)strpos($css, ':root'));
        $outside = substr($css, 0, (int)strpos($css, ':root')) . substr($css, (int)$rootEnd + 1);
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\\b|rgba?\\(|hsla?\\(/', $outside));
    }

    public function testSwV9AndI18nParity(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v9'", $sw);
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            foreach (['save_draft', 'draft_saved', 'draft_save_failed'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . ' missing ' . $key);
            }
        }
    }
}
