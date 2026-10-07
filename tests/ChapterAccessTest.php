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
use Core\Router;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\ChapterController;
use Writing\ChapterSynopsisController;
use Writing\ProjectAccess;
use Writing\WritingModule;

final class ChapterAccessTest extends TestCase
{
    private Database $db;
    private ProjectAccess $access;
    private ChapterController $controller;
    private ChapterSynopsisController $synopses;
    private WritingModule $module;
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
        $this->module = $module;
        $this->synopses = new ChapterSynopsisController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access);
        $this->controller = new ChapterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access, $renderer, $this->synopses);
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

    private function createSynopsis(User $author, string $title, string $summary = ''): string
    {
        $_POST = ['title' => $title, 'summary' => $summary];
        $this->synopses->store($author, 'p1');
        $_POST = [];
        $row = $this->db->one(
            'SELECT id FROM chapter_synopses WHERE project_id = ? AND title = ? ORDER BY created_at DESC, rowid DESC LIMIT 1',
            ['p1', $title]
        );
        return (string)$row['id'];
    }

    private function createChapter(User $author, string $slug, string $synopsisTitle = 'Chapter one', string $body = 'Body text'): void
    {
        $synopsisId = $this->createSynopsis($author, $synopsisTitle);
        $_POST = ['slug' => $slug, 'synopsis_id' => $synopsisId, 'body' => $body];
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

    public function testMemberCanReadChapter(): void
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
        $synopsisId = $this->createSynopsis($this->owner, 'Other title');
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => $synopsisId, 'body' => 'x'];
        $this->db->run(
            "INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('syn-p2', 'p2', 'syn-p2', 'P2 outline')"
        );
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => 'syn-p2', 'body' => 'x'];
        $this->controller->store($this->owner, 'p2');
        $_POST = [];
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM content_objects WHERE type = 'chapter' AND slug = 'chapter-1'"
        )['c'];
        $this->assertSame(2, $count);
    }

    public function testDuplicateSlugInSameProjectRejected(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $synopsisId = $this->createSynopsis($this->owner, 'Second outline');
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => $synopsisId, 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p1');
        $_POST = [];
        $this->assertSame(422, $response->status());
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM chapters WHERE project_id = 'p1' AND slug = 'chapter-1'"
        )['c'];
        $this->assertSame(1, $count);
    }

    public function testStoreRequiresSynopsisSelection(): void
    {
        $_POST = ['slug' => '', 'synopsis_id' => '', 'body' => 'My important text'];
        $response = $this->controller->store($this->owner, 'p1');
        $_POST = [];
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('Pick a chapter outline', $response->body());
        $this->assertStringContainsString('My important text', $response->body());
    }

    public function testStoreRejectsUnknownSynopsisId(): void
    {
        $_POST = ['slug' => '', 'synopsis_id' => 'does-not-exist', 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p1');
        $_POST = [];
        $this->assertSame(422, $response->status());
        $count = (int)$this->db->one("SELECT COUNT(*) AS c FROM chapters WHERE project_id = 'p1'")['c'];
        $this->assertSame(0, $count);
    }

    public function testChapterTitleComesFromSynopsis(): void
    {
        $synopsisId = $this->createSynopsis($this->owner, 'The Big Turning Point', 'The heist begins.');
        $_POST = ['slug' => '', 'synopsis_id' => $synopsisId, 'body' => 'Prose here'];
        $this->controller->store($this->owner, 'p1');
        $_POST = [];
        $title = $this->db->one(
            "SELECT title FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'the-big-turning-point'
             ORDER BY r.created_at DESC, r.rowid DESC LIMIT 1"
        )['title'];
        $this->assertSame('The Big Turning Point', (string)$title);
        $response = $this->controller->show($this->owner, 'p1', 'the-big-turning-point');
        $this->assertStringContainsString('The Big Turning Point', $response->body());
    }

    public function testAssignedSynopsisNotOfferedAgainInCreateDropdown(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'First outline');
        $this->createSynopsis($this->owner, 'Second outline');
        $response = $this->controller->create($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Second outline', $response->body());
        $this->assertStringNotContainsString('First outline', $response->body());
    }

    public function testEditDropdownListsUnassignedPlusCurrent(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'First outline');
        $this->createSynopsis($this->owner, 'Second outline');
        $response = $this->controller->edit($this->owner, 'p1', 'chapter-1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('First outline', $response->body());
        $this->assertStringContainsString('Second outline', $response->body());
    }

    public function testSynopsisUniqueAssignmentEnforced(): void
    {
        $synopsisId = $this->createSynopsis($this->owner, 'Shared outline');
        $this->createChapter($this->owner, 'chapter-1', 'First outline');
        $_POST = ['slug' => '', 'synopsis_id' => $synopsisId, 'body' => 'x'];
        $response = $this->controller->store($this->owner, 'p1');
        $_POST = [];
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('already assigned', $response->body());
        $count = (int)$this->db->one(
            "SELECT COUNT(*) AS c FROM chapters WHERE chapter_synopsis_id = ?",
            [$synopsisId]
        )['c'];
        $this->assertSame(0, $count);
    }

    public function testEditPageShowsSynopsisSummaryReadonlyWithoutEditLink(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'First outline', 'The summary text.');
        $response = $this->controller->edit($this->owner, 'p1', 'chapter-1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('The summary text.', $response->body());
        $this->assertStringContainsString('Chapter outline (read only)', $response->body());
        $this->assertStringNotContainsString('/summary', $response->body());
    }

    public function testEditPageHasNoTitleInput(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'First outline');
        $response = $this->controller->edit($this->owner, 'p1', 'chapter-1');
        $this->assertStringNotContainsString('name="title"', $response->body());
    }

    public function testUpdateCanReassignSynopsis(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'First outline');
        $newSynopsisId = $this->createSynopsis($this->owner, 'Second outline');
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => $newSynopsisId, 'body' => 'new body'];
        $this->controller->update($this->owner, 'p1', 'chapter-1');
        $_POST = [];
        $assigned = $this->db->one(
            "SELECT s.title FROM chapters c JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             WHERE c.project_id = 'p1' AND c.slug = 'chapter-1'"
        );
        $this->assertSame('Second outline', (string)$assigned['title']);
        $title = $this->db->one(
            "SELECT title FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-1'
             ORDER BY r.created_at DESC, r.rowid DESC LIMIT 1"
        )['title'];
        $this->assertSame('Second outline', (string)$title);
    }

    public function testUpdateToTakenSynopsisRejected(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'First outline');
        $this->createChapter($this->owner, 'chapter-2', 'Second outline');
        $takenId = $this->db->one(
            "SELECT c.chapter_synopsis_id FROM chapters c WHERE c.project_id = 'p1' AND c.slug = 'chapter-2'"
        )['chapter_synopsis_id'];
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => (string)$takenId, 'body' => 'x'];
        $response = $this->controller->update($this->owner, 'p1', 'chapter-1');
        $_POST = [];
        $this->assertSame(422, $response->status());
        $assigned = $this->db->one(
            "SELECT s.title FROM chapters c JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             WHERE c.project_id = 'p1' AND c.slug = 'chapter-1'"
        );
        $this->assertSame('First outline', (string)$assigned['title']);
    }

    public function testMemberCanEditChapter(): void
    {
        $this->createChapter($this->owner, 'chapter-1');
        $response = $this->controller->edit($this->member, 'p1', 'chapter-1');
        $this->assertSame(200, $response->status());
        $synopsisId = $this->db->one(
            "SELECT chapter_synopsis_id FROM chapters WHERE project_id = 'p1' AND slug = 'chapter-1'"
        )['chapter_synopsis_id'];
        $_POST = ['slug' => 'chapter-1', 'synopsis_id' => (string)$synopsisId, 'body' => 'new body'];
        $this->controller->update($this->member, 'p1', 'chapter-1');
        $_POST = [];
        $body = $this->db->one(
            "SELECT body FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-1'
             ORDER BY r.created_at DESC, r.rowid DESC LIMIT 1"
        )['body'];
        $this->assertSame('new body', (string)$body);
    }

    public function testDeleteChapterKeepsSynopsisAndFreesIt(): void
    {
        $synopsisId = $this->createSynopsis($this->owner, 'Kept outline', 'The summary survives.');
        $this->createChapter($this->owner, 'chapter-1', 'Kept outline');
        $response = $this->controller->destroy($this->owner, 'p1', 'chapter-1');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one(
            "SELECT id FROM content_objects WHERE type = 'chapter' AND slug = 'chapter-1'"
        ));
        $synopsis = $this->db->one(
            'SELECT id, title, summary_text FROM chapter_synopses WHERE id = ?',
            [$synopsisId]
        );
        $this->assertNotNull($synopsis);
        $this->assertSame('Kept outline', (string)$synopsis['title']);
        $this->assertSame('The summary survives.', (string)$synopsis['summary_text']);
        $offered = $this->synopses->unassigned('p1');
        $this->assertContains(['id' => $synopsisId, 'title' => 'Kept outline'], $offered);
        $this->createChapter($this->owner, 'chapter-again', 'Kept outline');
        $assigned = $this->db->one(
            "SELECT c.slug FROM chapters c WHERE c.chapter_synopsis_id = ?",
            [$synopsisId]
        );
        $this->assertNotNull($assigned);
    }

    public function testDeletingAssignedSynopsisBlockedWithMessage(): void
    {
        $this->createChapter($this->owner, 'chapter-1', 'Assigned outline');
        $slug = $this->db->one("SELECT slug FROM chapter_synopses WHERE title = 'Assigned outline'")['slug'];
        $response = $this->synopses->destroy($this->owner, 'p1', (string)$slug);
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('delete that chapter text first', $response->body());
        $this->assertStringContainsString('reassign it', $response->body());
        $this->assertNotNull($this->db->one("SELECT id FROM chapter_synopses WHERE slug = ?", [(string)$slug]));
    }

    public function testDeletingUnassignedSynopsisSucceeds(): void
    {
        $this->createSynopsis($this->owner, 'Free outline');
        $slug = $this->db->one("SELECT slug FROM chapter_synopses WHERE title = 'Free outline'")['slug'];
        $response = $this->synopses->destroy($this->owner, 'p1', (string)$slug);
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one("SELECT id FROM chapter_synopses WHERE slug = ?", [(string)$slug]));
    }

    public function testMemberCanDeleteOwnChapterOnly(): void
    {
        $this->createChapter($this->owner, 'owner-chapter', 'Owner outline');
        $this->createChapter($this->member, 'member-chapter', 'Member outline');
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

    public function testStrangerCannotCreateOrDeleteSynopsis(): void
    {
        $response = $this->synopses->create($this->stranger, 'p1');
        $this->assertSame(404, $response->status());
        $this->createChapter($this->owner, 'chapter-1', 'Owner outline');
        $slug = $this->db->one("SELECT slug FROM chapter_synopses WHERE title = 'Owner outline'")['slug'];
        $response = $this->synopses->destroy($this->stranger, 'p1', (string)$slug);
        $this->assertSame(404, $response->status());
    }

    public function testPreviewRouteIsNotSwallowedBySlugRoute(): void
    {
        $router = new Router();
        foreach ($this->module->routes($router) as $route) {
            $router->{$route['method'] === 'GET' ? 'get' : 'post'}(
                $route['pattern'],
                $route['handler'],
                $route['roles']
            );
        }
        $dispatched = $router->dispatch('POST', '/writing/p1/chapters/preview');
        $this->assertIsCallable($dispatched['handler']);
        $this->assertSame(['id' => 'p1'], $dispatched['params']);
    }

    public function testDuplicateTitleGetsAutoSlugSuffix(): void
    {
        $first = $this->createSynopsis($this->owner, 'Introduction');
        $_POST = ['slug' => '', 'synopsis_id' => $first, 'body' => 'a'];
        $this->controller->store($this->owner, 'p1');
        $second = $this->createSynopsis($this->owner, 'Introduction 2');
        $_POST = ['slug' => '', 'synopsis_id' => $second, 'body' => 'b'];
        $this->controller->store($this->owner, 'p1');
        $_POST = [];
        $slugs = $this->db->all(
            "SELECT o.slug FROM content_objects o JOIN chapters c ON c.id = o.id
             WHERE o.type = 'chapter' AND c.project_id = 'p1' ORDER BY o.slug"
        );
        $this->assertSame(['introduction', 'introduction-2'], array_column($slugs, 'slug'));
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->access, $this->controller, $this->synopses, $this->owner, $this->member, $this->stranger);
        $_POST = [];
    }
}
