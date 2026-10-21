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
use Core\ObjectDeleter;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\CharacterController;
use Writing\ProjectAccess;
use Writing\WritingModule;

/**
 * Character portrait upload: validation, access control, replacement,
 * removal and deletion cleanup, plus the access-checked delivery route.
 */
final class CharacterImageTest extends TestCase
{
    private Database $db;
    private CharacterController $controller;
    private ObjectDeleter $deleter;
    private User $owner;
    private User $member;
    private User $stranger;
    private string $basePath;
    /** Minimal 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $this->basePath = sys_get_temp_dir() . '/cb_character_' . uniqid();
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
        $this->controller = new CharacterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer, $this->basePath);
        $this->deleter = new ObjectDeleter($this->db, $registry);

        foreach ([['o1', 'owner'], ['m1', 'member'], ['s1', 'stranger']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = $this->user('o1');
        $this->member = $this->user('m1');
        $this->stranger = $this->user('s1');

        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'm1', 'member')");
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c1', 'character', 'hero', 'o1')"
        );
        $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES ('c1', 'p1', 'hero')");
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [Auth::uuid4(), 'c1', 'Hero', 'A brave hero.', 'o1']
        );
        $_FILES = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->basePath . '/data/characters/*') ?: [] as $f) {
            @unlink((string)$f);
        }
        @rmdir($this->basePath . '/data/characters');
        @unlink($this->basePath . '/data/contentbase.sqlite');
        @rmdir($this->basePath . '/data');
        @rmdir($this->basePath);
        $_FILES = [];
        $_POST = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }

    /** @param array<string, mixed>|null $override */
    private function fakeUpload(?string $mime = 'image/png', int $size = 92, ?array $override = null): string
    {
        $tmp = $this->basePath . '/upload.bin';
        file_put_contents($tmp, base64_decode(self::PNG));
        $_FILES['image'] = $override ?? [
            'name' => 'portrait.' . ($mime === 'image/jpeg' ? 'jpg' : 'png'),
            'type' => $mime,
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
        ];
        return $tmp;
    }

    /** Minimal 1x1 GIF. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private function fakeUploadGif(): string
    {
        $tmp = $this->basePath . '/upload.gif';
        file_put_contents($tmp, base64_decode(self::GIF));
        $_FILES['image'] = [
            'name' => 'portrait.gif',
            'type' => 'image/gif',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => 42,
        ];
        return $tmp;
    }

    private function imagePath(): ?string
    {
        $row = $this->db->one("SELECT image_path FROM characters WHERE id = 'c1'");
        return $row === null ? null : ($row['image_path'] === null ? null : (string)$row['image_path']);
    }

    public function testValidUploadIsAccepted(): void
    {
        $tmp = $this->fakeUpload();
        $response = $this->controller->uploadImage($this->owner, 'p1', 'hero');
        $this->assertSame(302, $response->status());
        $this->assertSame('c1.png', $this->imagePath());
        $this->assertFileExists($this->basePath . '/data/characters/c1.png');
        @unlink($tmp);
    }

    public function testMemberCanUpload(): void
    {
        $tmp = $this->fakeUpload();
        $response = $this->controller->uploadImage($this->member, 'p1', 'hero');
        $this->assertSame(302, $response->status());
        $this->assertSame('c1.png', $this->imagePath());
        @unlink($tmp);
    }

    public function testWrongMimeTypeRejected(): void
    {
        $tmp = $this->basePath . '/evil.txt';
        file_put_contents($tmp, 'not an image at all');
        $_FILES['image'] = [
            'name' => 'evil.txt',
            'type' => 'text/plain',
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => 19,
        ];
        $response = $this->controller->uploadImage($this->owner, 'p1', 'hero');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Only JPG, PNG, WebP and GIF', $response->body());
        $this->assertNull($this->imagePath());
        @unlink($tmp);
    }

    public function testOversizedUploadRejected(): void
    {
        $tmp = $this->fakeUpload('image/png', 5242881);
        $response = $this->controller->uploadImage($this->owner, 'p1', 'hero');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('must not be larger than 5 MB', $response->body());
        $this->assertNull($this->imagePath());
        @unlink($tmp);
    }

    public function testStrangerCannotUpload(): void
    {
        $tmp = $this->fakeUpload();
        $response = $this->controller->uploadImage($this->stranger, 'p1', 'hero');
        $this->assertSame(404, $response->status());
        $this->assertNull($this->imagePath());
        @unlink($tmp);
    }

    public function testReplacementDeletesOldFile(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        $old = $this->basePath . '/data/characters/c1.png';
        $this->assertFileExists($old);
        // Second upload as GIF -> different extension, so the new file has a
        // different name and we can assert the old PNG was deleted.
        $tmp = $this->fakeUploadGif();
        $response = $this->controller->uploadImage($this->owner, 'p1', 'hero');
        $this->assertSame(302, $response->status());
        $this->assertFileDoesNotExist($old);
        $this->assertSame('c1.gif', $this->imagePath());
        $this->assertFileExists($this->basePath . '/data/characters/c1.gif');
        @unlink($tmp);
    }

    public function testRemoveImageClearsFileAndColumn(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $response = $this->controller->removeImage($this->owner, 'p1', 'hero');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->imagePath());
        $this->assertFileDoesNotExist($this->basePath . '/data/characters/c1.png');
    }

    public function testRemovingCharacterDeletesImageFile(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $file = $this->basePath . '/data/characters/c1.png';
        $this->assertFileExists($file);
        $response = $this->controller->destroy($this->owner, 'p1', 'hero');
        $this->assertSame(302, $response->status());
        $this->assertFileDoesNotExist($file);
    }

    public function testObjectDeleterRemovesImageFile(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $file = $this->basePath . '/data/characters/c1.png';
        $this->assertFileExists($file);
        $this->deleter->delete('character', 'c1');
        $this->assertFileDoesNotExist($file);
        $this->assertNull($this->db->one("SELECT id FROM characters WHERE id = 'c1'"));
    }

    public function testImageRouteServesCorrectContentType(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $response = $this->controller->image($this->member, 'p1', 'hero');
        $this->assertSame(200, $response->status());
        $this->assertSame('image/png', $response->header('Content-Type'));
        $this->assertSame(base64_decode(self::PNG), $response->body());
    }

    public function testImageRoute404WithoutImage(): void
    {
        $response = $this->controller->image($this->owner, 'p1', 'hero');
        $this->assertSame(404, $response->status());
    }

    public function testImageRouteForbiddenForStranger(): void
    {
        $response = $this->controller->image($this->stranger, 'p1', 'hero');
        $this->assertSame(404, $response->status());
    }

    public function testImageRoute404AfterRemoval(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $this->controller->removeImage($this->owner, 'p1', 'hero');
        $response = $this->controller->image($this->owner, 'p1', 'hero');
        $this->assertSame(404, $response->status());
    }

    public function testCharacterPageShowsPortrait(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $response = $this->controller->show($this->owner, 'p1', 'hero');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('characters/hero/image', $response->body());
        $this->assertStringContainsString('character-portrait', $response->body());
    }

    public function testCharacterPageShowsRemoveActionWithImage(): void
    {
        $tmp = $this->fakeUpload();
        $this->controller->uploadImage($this->owner, 'p1', 'hero');
        @unlink($tmp);
        $response = $this->controller->show($this->owner, 'p1', 'hero');
        $this->assertStringContainsString('image/delete', $response->body());
        $this->assertStringContainsString(t('writing', 'remove_image'), $response->body());
    }
}
