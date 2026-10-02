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
use Writing\ChapterController;
use Writing\ProjectAccess;
use Writing\WritingModule;

final class ChapterAccessTest extends TestCase
{
    private Database $db;
    private ProjectAccess $access;
    private ChapterController $controller;
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
        $this->controller = new ChapterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access, $renderer);

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

    private function createChapter(User $author, string $slug, string $title = 'Chapter one'): void
    {
        $_POST = ['slug' => $slug, 'title' => $title, 'body' => 'Body text'];
        $this->controller->store($author, 'p1');
        $_POST = [];
    }

    public function testMemberCanCreateChapter(): void
    {
        $this->createChapter($this->member, 'member-chapter');
        $row = $this->db->one(
            "SELECT o.slug FROM content_objects o JOIN chapters c ON c.id = o.id WHERE c.project_id = 'p1' AND o.slug = 'member-chapter'"
        );
        $this->assertNotNull($row);
        $this->assertSame('m1', (string)$this->db->one(
            "SELECT owner_id FROM content_objects WHERE slug = 'member-chapter' AND type = 'chapter'"
        )['owner_id']);
    }

    public function testStrangerCannotCreateChapter(): void
    {
        $response = $this->controller->create($this->stranger, 'p1');
        $this->assertSame(404, $response->status());
    }

    public function testMemberCanReadButNotManageShares(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $response = $this->controller->show($this->member, 'p1', 'chapter-1');
        $this->assertSame(200, $response->status());
    }

    public function testStrangerCannotReadChapter(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $response = $this->controller->show($this->stranger, 'p1', 'chapter-1');
        $this->assertSame(404, $response->status());
    }

    public function testSlugUniquePerProject(): void
    {
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p2', 'o1', 'Other novel')");
        $this->createChapter($this->owner, 'chapter-1');
        $_POST = ['slug' => 'chapter-1', 'title' => 'Same slug other project', 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p2');
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM content_objects WHERE type = 'chapter' AND slug = 'chapter-1'"
        )['c'];
        $this->assertSame(2, $count);
    }

    public function testDuplicateSlugInSameProjectRejected(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $_POST = ['slug' => 'chapter-1', 'title' => 'Duplicate', 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM chapters WHERE project_id = 'p1' AND slug = 'chapter-1'"
        )['c'];
        $this->assertSame(1, $count);
    }

    public function testMemberCanEditChapter(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $response = $this->controller->edit($this->member, 'p1', 'chapter-1');
        $this->assertSame(200, $response->status());
        $_POST = ['slug' => 'chapter-1', 'title' => 'Edited by member', 'body' => 'new body'];
        $this->controller->update($this->member, 'p1', 'chapter-1');
        $title = $this->db->one(
            "SELECT title FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-1'
             ORDER BY r.created_at DESC LIMIT 1"
        )['title'];
        $this->assertSame('Edited by member', (string)$title);
    }

    public function testMemberCanDeleteOwnChapterOnly(): void
    {
        $this->createChapter($this->owner, 'owner-chapter');
        $this->createChapter($this->member, 'member-chapter');

        $response = $this->controller->destroy($this->member, 'p1', 'owner-chapter');
        $this->assertSame(403, $response->status());
        $this->assertNotNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'chapter' AND slug = 'owner-chapter'"
        ));

        $response = $this->controller->destroy($this->member, 'p1', 'member-chapter');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'chapter' AND slug = 'member-chapter'"
        ));
    }

    public function testOwnerCanDeleteAnyChapter(): void
    {
        $this->createChapter($this->member, 'member-chapter');
        $response = $this->controller->destroy($this->owner, 'p1', 'member-chapter');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'chapter' AND slug = 'member-chapter'"
        ));
    }

    public function testStrangerCannotDeleteChapter(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $response = $this->controller->destroy($this->stranger, 'p1', 'chapter-1');
        $this->assertSame(404, $response->status());
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->access, $this->controller, $this->owner, $this->member, $this->stranger);
        $_POST = [];
    }
}
