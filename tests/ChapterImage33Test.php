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
use Writing\StoryExporter;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * Prompt 33 - chapter illustration: one uploadable image per chapter
 * (data/chapters/<id>.<ext>, guarded GET route, edit-form block, read-page
 * display, offline manifest, export into the ZIP bundle).
 */
final class ChapterImage33Test extends TestCase
{
    private string $base;
    private string $basePath;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $owner;
    private User $member;
    private User $stranger;

    /** 1x1 PNG (valid for getimagesize). */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->basePath = sys_get_temp_dir() . '/cb_chimg_' . uniqid();
        mkdir($this->basePath . '/data', 0775, true);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $this->db = new Database($this->basePath . '/data/contentbase.sqlite');
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, new Auth($this->db)), new ContentRenderer($this->db, new ModuleRegistry()), $this->basePath);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->auth = new Auth($this->db);
        foreach (['a1' => 'alice', 'm1' => 'bob', 's1' => 'mallory'] as $id => $name) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = $this->user('a1');
        $this->member = $this->user('m1');
        $this->stranger = $this->user('s1');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'a1', 'Novel')");
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'm1', 'member')");
        $this->db->run("INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('s1', 'p1', 'outline', 'Outline')");
        $this->db->run("INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c1', 'p1', 's1', 'ch1')");
        $this->db->run("INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c1', 'chapter', 'ch1', 'a1')");
        $this->db->run("INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES ('r1', 'c1', 'Ch1', 'body text', 'a1')");
        $_FILES = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->owner, $this->member, $this->stranger);
        $_FILES = [];
        $_POST = [];
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

    private function pngUpload(int $sizeOverride = null): array
    {
        $tmp = $this->basePath . '/upload.png';
        file_put_contents($tmp, base64_decode(self::PNG_1PX));
        return [
            'name' => 'pic.png',
            'type' => 'image/png',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => $sizeOverride ?? filesize($tmp),
        ];
    }

    public function testMigrationAddsImagePathColumnAndRoutesExist(): void
    {
        $cols = array_column($this->db->all('PRAGMA table_info(chapters)'), 'name');
        $this->assertContains('image_path', $cols);
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ContentRenderer($this->db, new ModuleRegistry()), $this->basePath);
        $patterns = [];
        foreach ($module->routes(new \Core\Router()) as $r) {
            $patterns[] = $r['method'] . ' ' . $r['pattern'];
        }
        $this->assertContains('POST /writing/{id}/chapters/{slug}/image', $patterns);
        $this->assertContains('POST /writing/{id}/chapters/{slug}/image/delete', $patterns);
        $this->assertContains('GET /writing/{id}/chapters/{slug}/image', $patterns);
    }

    public function testUploadStoresFileAndColumnAndServesGuarded(): void
    {
        $_FILES['image'] = $this->pngUpload();
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(302, $r->status());
        $this->assertSame('/writing/p1/chapters/ch1/edit', $r->header('Location'));
        $row = $this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'");
        $this->assertSame('c1.png', $row['image_path']);
        $this->assertFileExists($this->basePath . '/data/chapters/c1.png');
        // serving: member 200 with image mime, stranger 404, missing image 404
        $img = $this->chapters()->image($this->member, 'p1', 'ch1');
        $this->assertSame(200, $img->status());
        $this->assertSame('image/png', $img->header('Content-Type'));
        $this->assertSame(404, $this->chapters()->image($this->stranger, 'p1', 'ch1')->status());
        $this->assertSame(404, $this->chapters()->image($this->owner, 'p1', 'nope')->status());
    }

    public function testUploadRejectsOversizeAndBogusContent(): void
    {
        $_FILES['image'] = $this->pngUpload(6 * 1024 * 1024); // > 5 MB
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(200, $r->status(), 'error re-renders the edit form');
        $this->assertStringContainsString('too large', $r->body());
        $this->assertNull($this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'")['image_path']);
        // bogus content with image mime -> getimagesize check rejects
        $tmp = $this->basePath . '/fake.png';
        file_put_contents($tmp, 'this is not an image');
        $_FILES['image'] = ['name' => 'fake.png', 'type' => 'image/png', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
        $r = $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertSame(200, $r->status());
        $this->assertNull($this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'")['image_path']);
        $this->assertFileDoesNotExist($this->basePath . '/data/chapters/c1.png');
        // guard: stranger cannot upload
        $_FILES['image'] = $this->pngUpload();
        $this->assertSame(404, $this->chapters()->uploadImage($this->stranger, 'p1', 'ch1')->status());
    }

    public function testRemoveClearsColumnAndFile(): void
    {
        $_FILES['image'] = $this->pngUpload();
        $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $this->assertFileExists($this->basePath . '/data/chapters/c1.png');
        $r = $this->chapters()->removeImage($this->owner, 'p1', 'ch1');
        $this->assertSame(302, $r->status());
        $this->assertNull($this->db->one("SELECT image_path FROM chapters WHERE id = 'c1'")['image_path']);
        $this->assertFileDoesNotExist($this->basePath . '/data/chapters/c1.png', 'no orphan files');
        $this->assertSame(404, $this->chapters()->removeImage($this->stranger, 'p1', 'ch1')->status());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testReadPageShowsIllustrationOnlyWhenPresent(): void
    {
        $c = $this->chapters();
        $body = $c->show($this->member, 'p1', 'ch1')->body();
        $this->assertStringNotContainsString('chapter-illustration', $body, 'no image -> nothing rendered');
        $_FILES['image'] = $this->pngUpload();
        $c->uploadImage($this->owner, 'p1', 'ch1');
        $body = $c->show($this->member, 'p1', 'ch1')->body();
        $this->assertStringContainsString('class="chapter-illustration"', $body);
        $this->assertMatchesRegularExpression('#/chapters/ch1/image\?v=\d+#', $body, 'mtime cache-buster');
        $this->assertStringContainsString('alt="Outline"', $body, 'alt = chapter title (synopsis title here)');
    }

    public function testOfflineManifestIncludesChapterImageUrl(): void
    {
        $_FILES['image'] = $this->pngUpload();
        $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $view = new View($this->base);
        $csrf = new Csrf($this->db, $this->auth);
        $writing = new WritingController($this->db, $view, $csrf, new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()), $this->basePath);
        $data = json_decode($writing->offlineManifest($this->member, 'p1')->body(), true);
        $this->assertContains('/writing/p1/chapters/ch1/image', $data['urls']);
    }

    public function testExportZipContainsImageFileAndMarkdownReference(): void
    {
        $_FILES['image'] = $this->pngUpload();
        $this->chapters()->uploadImage($this->owner, 'p1', 'ch1');
        $exporter = new StoryExporter($this->db, $this->basePath);
        $result = $exporter->export('p1');
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($result['path']) === true);
        $this->assertNotFalse($zip->locateName('images/chapter-c1.png'), 'image file in the ZIP');
        $md = $zip->getFromName('chapters/ch1.md');
        $this->assertStringContainsString('](images/chapter-c1.png)', $md, 'image markdown reference in the chapter file');
        $this->assertStringContainsString('![Outline](images/chapter-c1.png)', $md);
        $zip->close();
        unlink($result['path']);
    }

    public function testTemplatesAndSwAndI18n(): void
    {
        $edit = (string)file_get_contents($this->base . '/modules/Writing/templates/chapter_edit.php');
        $this->assertStringContainsString("chapter_image_upload", $edit);
        $this->assertStringContainsString('enctype="multipart/form-data"', $edit);
        $this->assertStringContainsString('/image/delete', $edit);
        $this->assertStringContainsString('chapter-edit-preview', $edit);
        $read = (string)file_get_contents($this->base . '/modules/Writing/templates/chapter.php');
        $this->assertStringContainsString('chapter-illustration', $read);
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString('.chapter-illustration', $css);
        $this->assertStringContainsString('object-fit: cover;', $css);
        $rootEnd = strpos($css, '}', (int)strpos($css, ':root'));
        $outside = substr($css, 0, (int)strpos($css, ':root')) . substr($css, (int)$rootEnd + 1);
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside));
        $sw = (string)file_get_contents($this->base . '/public/sw.js');
        $this->assertStringContainsString("const VERSION = 'v11'", $sw);
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/writing.php');
            foreach (['chapter_image', 'chapter_image_upload', 'chapter_image_remove', 'chapter_image_too_big', 'confirm_remove_chapter_image'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . ' missing ' . $key);
            }
        }
    }
}
