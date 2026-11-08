<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\AuthController;
use Core\Avatar;
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
 * Prompt 24: (A) avatar stack in the story header, (B) self-service user
 * avatar upload on /profile (mirrors the character image upload: same
 * ImageStore validation, data/avatars/ storage, access-checked serving route)
 * with a single-source renderer (img when set, initials circle otherwise).
 */
final class UserAvatarTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $base;
    private string $tmpBase;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $user;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->tmpBase = sys_get_temp_dir() . '/cb_avatar_' . uniqid();
        mkdir($this->tmpBase . '/data', 0775, true);
        Avatar::$basePathOverride = $this->tmpBase;
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $this->db = new Database($this->tmpBase . '/data/contentbase.sqlite');
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('x1234567', PASSWORD_BCRYPT), 'admin']
        );
        $this->user = User::fromRow($this->db->one(
            'SELECT id, username, role, language, theme, avatar FROM users WHERE id = ?',
            ['u1']
        ));
        $_POST = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        Avatar::$basePathOverride = null;
        $_POST = [];
        $_FILES = [];
        unset($this->db, $this->auth, $this->user);
        foreach (glob($this->tmpBase . '/data/avatars/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpBase . '/data/avatars');
        @unlink($this->tmpBase . '/data/contentbase.sqlite');
        @rmdir($this->tmpBase . '/data');
        @rmdir($this->tmpBase);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function controller(): AuthController
    {
        return new AuthController($this->auth, new View($this->base), new Csrf($this->db, $this->auth), Translator::instance(), null, null, $this->tmpBase);
    }

    /** @param array<string, mixed>|null $override */
    private function fakeUpload(?string $mime = 'image/png', int $size = 92, ?array $override = null): void
    {
        $tmp = $this->tmpBase . '/upload.bin';
        file_put_contents($tmp, base64_decode(self::PNG));
        $_FILES['avatar'] = $override ?? [
            'name' => 'avatar.png',
            'type' => $mime,
            'tmp_name' => $tmp,
            'error' => UPLOAD_ERR_OK,
            'size' => $size,
        ];
    }

    private function avatarColumn(): ?string
    {
        $row = $this->db->one('SELECT avatar FROM users WHERE id = ?', ['u1']);
        return $row === null || $row['avatar'] === null ? null : (string)$row['avatar'];
    }

    public function testMigrationAddsNullableAvatarColumn(): void
    {
        $this->assertNull($this->avatarColumn());
        $this->assertNull($this->user->avatar());
    }

    public function testUploadPersistsFileAndColumnAndRendersImg(): void
    {
        $this->fakeUpload();
        $response = $this->controller()->uploadAvatar($this->user);
        $this->assertSame(200, $response->status());
        $name = $this->avatarColumn();
        $this->assertNotNull($name);
        $this->assertMatchesRegularExpression('/^u1\\.(png|jpg|webp|gif)$/', $name, 'stored name is userId.ext (traversal-safe; store() basenames anyway)');
        $this->assertFileExists($this->tmpBase . '/data/avatars/' . $name);
        $body = $this->controller()->showProfile($this->auth->refreshUser($this->user))->body();
        $this->assertStringContainsString('avatar-img', $body);
        $this->assertStringContainsString('/avatars/u1?v=', $body);
        $this->assertStringContainsString('loading="lazy"', $body);
    }

    public function testInvalidUploadsAreRejected422AndKeepAvatar(): void
    {
        $this->fakeUpload('text/plain', 19, ['name' => 'evil.txt', 'type' => 'text/plain']);
        $r = $this->controller()->uploadAvatar($this->user);
        $this->assertSame(422, $r->status());
        $this->assertNull($this->avatarColumn());

        $this->fakeUpload('image/png', \Core\ImageStore::MAX_BYTES + 1);
        $r = $this->controller()->uploadAvatar($this->user);
        $this->assertSame(422, $r->status());
        $this->assertNull($this->avatarColumn());

        unset($_FILES['avatar']);
        $r = $this->controller()->uploadAvatar($this->user);
        $this->assertSame(422, $r->status());
        $this->assertNull($this->avatarColumn());
    }

    public function testRemoveRestoresInitialsFallback(): void
    {
        $this->fakeUpload();
        $this->controller()->uploadAvatar($this->user);
        $this->assertNotNull($this->avatarColumn());
        $r = $this->controller()->removeAvatar($this->user);
        $this->assertSame(200, $r->status());
        $this->assertNull($this->avatarColumn());
        $this->assertFileDoesNotExist($this->tmpBase . '/data/avatars/u1.png');
        $body = $this->controller()->showProfile($this->user)->body();
        $this->assertStringContainsString('avatar-tone-', $body, 'initials circle fallback');
        $this->assertStringNotContainsString('avatar-img', $body);
    }

    public function testAvatarRouteServesImageAnd404s(): void
    {
        $c = $this->controller();
        $this->assertSame(404, $c->avatarImage($this->user, 'u1')->status(), 'no avatar yet');
        $this->assertSame(404, $c->avatarImage($this->user, 'nobody')->status());
        $this->fakeUpload();
        $c->uploadAvatar($this->user);
        $r = $c->avatarImage($this->user, 'u1');
        $this->assertSame(200, $r->status());
        $this->assertSame('image/png', $r->header('Content-Type'));
    }

    public function testOverviewStackRendersImgWhenMemberHasAvatar(): void
    {
        // member m1 with an uploaded avatar file
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['m1', 'bob', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        mkdir($this->tmpBase . '/data/avatars', 0775, true);
        file_put_contents($this->tmpBase . '/data/avatars/m1.png', base64_decode(self::PNG));
        $this->db->run("UPDATE users SET avatar = 'm1.png' WHERE id = 'm1'");
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ContentRenderer($this->db, new ModuleRegistry()), $this->tmpBase);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'u1', 'My novel')");
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'm1', 'member')");
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()), $this->tmpBase);
        $body = $controller->index($this->user)->body();
        $this->assertStringContainsString('avatar-img', $body, 'uploaded avatar renders as img in the overview stack');
        $this->assertStringContainsString('/avatars/m1?v=', $body);
        $this->assertStringContainsString('alt="bob"', $body);
    }

    /**
     * project.php declares helper functions - render at most once per process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStoryHeaderShowsAvatarStackOnce(): void
    {
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ContentRenderer($this->db, new ModuleRegistry()), $this->tmpBase);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'u1', 'My novel')");
        foreach (['m1', 'm2', 'm3', 'm4'] as $m) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$m, $m, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
            $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', ?, 'member')", [$m]);
        }
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()), $this->tmpBase);
        $body = $controller->show($this->user, 'p1')->body();
        // header contains the stack exactly once, linked to setup (owner view)
        $this->assertSame(1, substr_count($body, 'class="story-header"'));
        $this->assertSame(1, substr_count($body, 'avatar-stack share-stack'));
        $this->assertStringContainsString('href="/writing/p1/setup"', $body);
        $this->assertStringContainsString('aria-label="Shared with m1, m2, m3, m4 (4) - manage sharing"', $body);
        $this->assertStringContainsString('>+1</span>', $body, '+N counter for the 4th member');
        // capped at 3 circles inside the story header (the topbar avatar lives in <header>)
        $header = substr($body, (int)strpos($body, 'class="story-header"'), (int)strpos($body, 'story-menu-wrap') - (int)strpos($body, 'class="story-header"'));
        $this->assertSame(3, substr_count($header, 'avatar-tone-') + substr_count($header, 'avatar-img'), 'capped at 3 circles');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testStoryHeaderWithoutMembersShowsNoStack(): void
    {
        $module = new WritingModule($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ContentRenderer($this->db, new ModuleRegistry()), $this->tmpBase);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'u1', 'My novel')");
        $controller = new WritingController($this->db, new View($this->base), new Csrf($this->db, $this->auth), new ProjectAccess($this->db), new ContentRenderer($this->db, new ModuleRegistry()), $this->tmpBase);
        $body = $controller->show($this->user, 'p1')->body();
        $this->assertStringContainsString('class="story-header"', $body);
        $this->assertStringNotContainsString('avatar-stack', $body);
    }

    public function testAvatarKeysExistInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/core.php');
            foreach (['profile_avatar', 'avatar_upload', 'avatar_remove', 'avatar_hint', 'avatar_changed', 'avatar_removed', 'profile_avatar_no_file', 'profile_avatar_too_big', 'profile_avatar_invalid'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . '/core.php missing ' . $key);
            }
        }
    }
}
