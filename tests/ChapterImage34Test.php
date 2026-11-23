<?php

declare(strict_types=1);

namespace Tests\Writing;

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
 * Prompt 34 - control & fix: chapter image upload is fetch-only and can never
 * touch the editor (no nested form, no re-render error path, JSON responses,
 * client-side pre-validation).
 */
final class ChapterImage34Test extends TestCase
{
    private string $base;
    private string $basePath;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $owner;
    private User $stranger;

    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->basePath = sys_get_temp_dir() . '/cb_chimg34_' . uniqid();
        mkdir($this->basePath . '/data', 0775, true);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $this->db = new Database($this->basePath . '/data/contentbase.sqlite');
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, new Auth($this->db)), new ContentRenderer($this->db, new ModuleRegistry()), $this->basePath);
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
        $_FILES = [];
        $_POST = [];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->owner, $this->stranger);
        $_FILES = [];
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

    private function chapters(): ChapterController
    {
        $view = new View($this->base);
        $csrf = new Csrf($this->db, $this->auth);
        $access = new ProjectAccess($this->db);
        $renderer = new ContentRenderer($this->db, new ModuleRegistry());
        return new ChapterController($this->db, $view, $csrf, $access, $renderer, new ChapterSynopsisController($this->db, $view, $csrf, $access, $renderer), null, $this->basePath);
    }

    private function pngUpload(int $sizeOverride = null, string $type = 'image/png'): array
    {
        $tmp = $this->basePath . '/upload.png';
        file_put_contents($tmp, base64_decode(self::PNG_1PX));
        return ['name' => 'pic.png', 'type' => $type, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => $sizeOverride ?? filesize($tmp)];
    }

    public function testAjaxUploadSuccessReturnsJsonWithUrlAndVersion(): void
    {
        $_FILES['image'] = $this->pngUpload();
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(200, $r->status());
        $this->assertSame('application/json; charset=utf-8', $r->header('Content-Type'));
        $this->assertNull($r->header('Location'), 'no navigation for image actions');
        $data = json_decode($r->body(), true);
        $this->assertTrue($data['ok']);
        $this->assertSame('/writing/p1/chapters/ch1/image', $data['url']);
        $this->assertIsInt($data['version'], 'mtime cache-buster version');
        $this->assertSame('c1.png', $this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'")['image_path']);
    }

    public function testOversizedUploadReturns422JsonNotHtmlRerender(): void
    {
        // the mobile scenario: a straight-from-camera photo > 5 MB
        $_FILES['image'] = $this->pngUpload(8 * 1024 * 1024);
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(422, $r->status());
        $this->assertSame('application/json; charset=utf-8', $r->header('Content-Type'), 'JSON, never an HTML re-render');
        $this->assertStringNotContainsString('<!DOCTYPE', $r->body());
        $data = json_decode($r->body(), true);
        $this->assertFalse($data['ok']);
        $this->assertNotEmpty($data['errors']);
        $this->assertNull($this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'")['image_path'], 'nothing stored');
    }

    public function testInvalidTypeReturns422Json(): void
    {
        $tmp = $this->basePath . '/x.heic';
        file_put_contents($tmp, 'heic-ish');
        $_FILES['image'] = ['name' => 'x.heic', 'type' => 'image/heic', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 12];
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(422, $r->status());
        $this->assertSame('application/json; charset=utf-8', $r->header('Content-Type'));
    }

    public function testAjaxRemoveReturnsJsonUrlNull(): void
    {
        $_FILES['image'] = $this->pngUpload();
        $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $r = $this->chapters()->removeImage($this->owner, 'p1', 'ch1');
        $this->assertSame(200, $r->status());
        $data = json_decode($r->body(), true);
        $this->assertTrue($data['ok']);
        $this->assertNull($data['url']);
        $this->assertNull($this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'")['image_path']);
        $this->assertFileDoesNotExist($this->basePath . '/data/chapters/c1.png');
    }

    public function testNonAjaxFallbackStillRedirectsAndGuardsHold(): void
    {
        unset($_SERVER['HTTP_ACCEPT'], $_SERVER['HTTP_X_REQUESTED_WITH']);
        $_FILES['image'] = $this->pngUpload();
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(302, $r->status(), 'non-AJAX fallback redirect kept');
        $this->assertSame(404, $this->chapters()->uploadImage($this->stranger, 'p1', 'ch1')->status());
    }

    public function testStructureGuardImageFormsAreOutsideTheSaveForm(): void
    {
        $tpl = (string)file_get_contents($this->base . '/modules/Writing/templates/chapter_edit.php');
        $formStart = strpos($tpl, 'id="chapter-form"');
        $formEnd = strpos($tpl, '</form>', $formStart);
        $imageForms = strpos($tpl, 'image-action');
        $this->assertNotFalse($formStart);
        $this->assertNotFalse($formEnd);
        $this->assertNotFalse($imageForms);
        $this->assertGreaterThan($formEnd, $imageForms, 'image forms must live AFTER the save form closes - no auxiliary form may wrap the editor');
        // only the save form contains the editor textarea
        $bodyLabel = strpos($tpl, 'name="body"');
        $this->assertLessThan($formEnd, $bodyLabel, 'editor textarea stays inside the save form');
        $this->assertGreaterThan($formEnd, strpos($tpl, 'name="image"'), 'file input stays outside the save form');
        // the re-render error path is gone from the controller
        $cc = (string)file_get_contents($this->base . '/modules/Writing/src/ChapterController.php');
        $this->assertStringNotContainsString('editWithImageError', $cc);
        $this->assertStringContainsString('jsonError($error)', $cc, 'AJAX errors are 422 JSON');
    }

    public function testAppJsImageActionsAreFetchOnlyWithPrevalidation(): void
    {
        $js = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString("querySelectorAll('form.image-action')", $js);
        $this->assertStringContainsString('ev.preventDefault()', $js);
        $this->assertStringContainsString("'image/jpeg', 'image/png', 'image/webp', 'image/gif'", $js, 'type whitelist');
        $this->assertStringContainsString('var MAX_BYTES = 5242880;', $js, '5 MB limit checked in the browser BEFORE fetch');
        $this->assertStringContainsString('file.size > MAX_BYTES', $js);
        $this->assertStringContainsString('ACCEPTED_TYPES.indexOf(file.type)', $js);
        $this->assertStringContainsString('new FormData(form)', $js);
        $this->assertStringContainsString("'X-Requested-With': 'XMLHttpRequest'", $js);
        $this->assertStringContainsString('.image-preview', $js, 'only the preview is swapped on success');
        $block = substr($js, (int)strpos($js, 'Prompt 34'));
        $this->assertStringNotContainsString('location.reload()', $block, 'image actions never reload (the prompt-32 rescue restore is a different, explicit flow)');
        $this->assertStringNotContainsString('location.href', $block, 'image actions never navigate');
    }

    public function testSwV12AndI18nParity(): void
    {
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v12'", $sw);
        $counts = [];
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            foreach (['image_too_large', 'image_type_invalid', 'image_uploaded', 'image_removed'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . ' missing ' . $key);
            }
            $counts[] = preg_match_all("/^\s*'[^']+'\s*=>/m", $src);
        }
        $this->assertSame([$counts[0], $counts[0], $counts[0], $counts[0]], $counts, 'writing parity');
    }
}
