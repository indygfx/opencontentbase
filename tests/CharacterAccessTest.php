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
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\CharacterController;
use Writing\ProjectAccess;
use Writing\WritingModule;

final class CharacterAccessTest extends TestCase
{
    private Database $db;
    private ProjectAccess $access;
    private CharacterController $controller;
    private User $owner;
    private User $member;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new ContentRenderer($this->db, $registry);
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->access = new ProjectAccess($this->db);
        $this->controller = new CharacterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access, $renderer);

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
        $_POST = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }

    private function createCharacter(User $author, string $slug, string $title = 'Chapter one'): void
    {
        $_POST = ['slug' => $slug, 'title' => $title, 'body' => 'Body text'];
        $this->controller->store($author, 'p1');
        $_POST = [];
    }

    public function testMemberCanCreateChapter(): void
    {
        $this->createCharacter($this->member, 'member-hero');
        $row = $this->db->one(
            "SELECT o.slug FROM content_objects o JOIN characters c ON c.id = o.id WHERE c.project_id = 'p1' AND o.slug = 'member-hero'"
        );
        $this->assertNotNull($row);
        $this->assertSame('m1', (string)$this->db->one(
            "SELECT owner_id FROM content_objects WHERE slug = 'member-hero' AND type = 'character'"
        )['owner_id']);
    }

    public function testStrangerCannotCreateChapter(): void
    {
        $response = $this->controller->create($this->stranger, 'p1');
        $this->assertSame(404, $response->status());
    }

    public function testMemberCanReadButNotManageShares(): void
    {
        $this->createCharacter($this->owner, 'hero-1');
        $response = $this->controller->show($this->member, 'p1', 'hero-1');
        $this->assertSame(200, $response->status());
    }

    public function testStrangerCannotReadChapter(): void
    {
        $this->createCharacter($this->owner, 'hero-1');
        $response = $this->controller->show($this->stranger, 'p1', 'hero-1');
        $this->assertSame(404, $response->status());
    }

    public function testSlugUniquePerProject(): void
    {
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p2', 'o1', 'Other novel')");
        $this->createCharacter($this->owner, 'hero-1');
        $_POST = ['slug' => 'hero-1', 'title' => 'Same slug other project', 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p2');
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM content_objects WHERE type = 'character' AND slug = 'hero-1'"
        )['c'];
        $this->assertSame(2, $count);
    }

    public function testDuplicateSlugInSameProjectRejected(): void
    {
        $this->createCharacter($this->owner, 'hero-1');
        $_POST = ['slug' => 'hero-1', 'title' => 'Duplicate', 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM characters WHERE project_id = 'p1' AND slug = 'hero-1'"
        )['c'];
        $this->assertSame(1, $count);
    }

    public function testMemberCanEditChapter(): void
    {
        $this->createCharacter($this->owner, 'hero-1');
        $response = $this->controller->edit($this->member, 'p1', 'hero-1');
        $this->assertSame(200, $response->status());
        $_POST = ['slug' => 'hero-1', 'title' => 'Edited by member', 'body' => 'new body'];
        $this->controller->update($this->member, 'p1', 'hero-1');
        $title = $this->db->one(
            "SELECT title FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'character' AND o.slug = 'hero-1'
             ORDER BY r.created_at DESC LIMIT 1"
        )['title'];
        $this->assertSame('Edited by member', (string)$title);
    }

    public function testMemberCanDeleteOwnChapterOnly(): void
    {
        $this->createCharacter($this->owner, 'owner-hero');
        $this->createCharacter($this->member, 'member-hero');

        $response = $this->controller->destroy($this->member, 'p1', 'owner-hero');
        $this->assertSame(403, $response->status());
        $this->assertNotNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'character' AND slug = 'owner-hero'"
        ));

        $response = $this->controller->destroy($this->member, 'p1', 'member-hero');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'character' AND slug = 'member-hero'"
        ));
    }

    public function testOwnerCanDeleteAnyChapter(): void
    {
        $this->createCharacter($this->member, 'member-hero');
        $response = $this->controller->destroy($this->owner, 'p1', 'member-hero');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'character' AND slug = 'member-hero'"
        ));
    }

    public function testStrangerCannotDeleteChapter(): void
    {
        $this->createCharacter($this->owner, 'hero-1');
        $response = $this->controller->destroy($this->stranger, 'p1', 'hero-1');
        $this->assertSame(404, $response->status());
    }

    public function testDuplicateTitleGetsAutoSlugSuffix(): void
    {
        $_POST = ['slug' => '', 'title' => 'Introduction', 'body' => 'a'];
        $this->controller->store($this->owner, 'p1');
        $_POST = ['slug' => '', 'title' => 'Introduction', 'body' => 'b'];
        $this->controller->store($this->owner, 'p1');
        $slugs = $this->db->all(
            "SELECT o.slug FROM content_objects o JOIN characters k ON k.id = o.id
             WHERE o.type = 'character' AND k.project_id = 'p1' ORDER BY o.slug"
        );
        $this->assertSame(['introduction', 'introduction-2'], array_column($slugs, 'slug'));
    }

    public function testValidationErrorKeepsBody(): void
    {
        $_POST = ['slug' => '', 'title' => '', 'body' => 'My important text'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('My important text', $response->body());
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->access, $this->controller, $this->owner, $this->member, $this->stranger);
        $_POST = [];
    }
}
