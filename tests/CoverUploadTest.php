<?php declare(strict_types=1);

namespace Tests\Writing;

use Core\Auth;
use Core\ContentRenderer;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * Album cover upload for the writing module: validation, access control
 * and persistence behaviour of the cover endpoints.
 */
final class CoverUploadTest extends TestCase
{
    private Database $db;
    private WritingController $controller;
    private User $owner;
    private User $stranger;
    private string $basePath;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $this->basePath = sys_get_temp_dir() . '/cb_cover_' . uniqid();
        mkdir($this->basePath . '/data', 0775, true);
        $path = $this->basePath . '/data/contentbase.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new ContentRenderer($this->db, $registry);
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer, $this->basePath);
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $access = new ProjectAccess($this->db);
        $this->controller = new WritingController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer, $this->basePath);

        foreach ([['o1', 'owner'], ['s1', 'stranger']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $this->stranger = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 's1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $_FILES = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/data/covers/*') ?: [] as $f) {
            @unlink((string)$f);
        }
        @rmdir($this->basePath . '/data/covers');
        @unlink($this->basePath . '/data/contentbase.sqlite');
        @rmdir($this->basePath . '/data');
        @rmdir($this->basePath);
        $_FILES = [];
        $_POST = [];
    }

    private function fakeUpload(string $tmpPath, string $mime, int $size = 1024): void
    {
        $_FILES['cover'] = [
            'name' => 'cover.' . $mime,
            'type' => $mime,
            'tmp_name' => $tmpPath,
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
        ];
    }

    public function testCoverColumnDefaultsToEmpty(): void
    {
        $cover = (string)$this->db->one("SELECT cover FROM projects WHERE id = 'p1'")['cover'];
        $this->assertSame('', $cover);
    }

    public function testUploadRejectsWrongMimeType(): void
    {
        $tmp = $this->basePath . '/evil.txt';
        file_put_contents($tmp, 'not an image');
        $this->fakeUpload($tmp, 'text/plain');
        $response = $this->controller->uploadCover($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Only JPG, PNG, WebP and GIF', $response->body());
        unlink($tmp);
    }

    public function testUploadRejectsMissingFile(): void
    {
        $_FILES = [];
        $response = $this->controller->uploadCover($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('No file was uploaded', $response->body());
    }

    public function testUploadRejectsStranger(): void
    {
        $tmp = $this->basePath . '/img.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents($tmp, $png);
        $this->fakeUpload($tmp, 'image/png');
        $response = $this->controller->uploadCover($this->stranger, 'p1');
        $this->assertSame(403, $response->status());
        $this->assertSame('', (string)$this->db->one("SELECT cover FROM projects WHERE id = 'p1'")['cover']);
        unlink($tmp);
    }

    public function testUploadPersistsCoverName(): void
    {
        $tmp = $this->basePath . '/img.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents($tmp, $png);
        $this->fakeUpload($tmp, 'image/png');
        $response = $this->controller->uploadCover($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $this->assertSame('p1.png', (string)$this->db->one("SELECT cover FROM projects WHERE id = 'p1'")['cover']);
        $this->assertFileExists($this->basePath . '/data/covers/p1.png');
        unlink($tmp);
    }

    public function testRemoveCoverClearsFileAndColumn(): void
    {
        $tmp = $this->basePath . '/img.png';
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        file_put_contents($tmp, $png);
        $this->fakeUpload($tmp, 'image/png');
        $this->controller->uploadCover($this->owner, 'p1');
        unlink($tmp);

        $response = $this->controller->removeCover($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $this->assertSame('', (string)$this->db->one("SELECT cover FROM projects WHERE id = 'p1'")['cover']);
        $this->assertFileDoesNotExist($this->basePath . '/data/covers/p1.png');
    }

    public function testCoverDeliveryRequiresAccess(): void
    {
        $response = $this->controller->cover($this->owner, 'p1');
        $this->assertSame(404, $response->status());
    }
}
