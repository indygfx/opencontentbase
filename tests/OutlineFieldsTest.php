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
use Writing\ChapterSynopsisController;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

final class OutlineFieldsTest extends TestCase
{
    private Database $db;
    private ChapterController $chapters;
    private ChapterSynopsisController $synopses;
    private WritingController $projects;
    private User $owner;

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
        $access = new ProjectAccess($this->db);
        $this->synopses = new ChapterSynopsisController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer);
        $this->chapters = new ChapterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer, $this->synopses);
        $this->projects = new WritingController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['o1', 'owner', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->owner = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $_POST = [];
    }

    public function testChapterTakesTitleFromSynopsis(): void
    {
        $_POST = ['title' => 'Chapter one', 'summary' => 'The basic idea of this chapter'];
        $this->synopses->store($this->owner, 'p1');
        $_POST = [];
        $synopsisId = (string)$this->db->one(
            "SELECT id FROM chapter_synopses WHERE project_id = 'p1' AND slug = 'chapter-one'"
        )['id'];
        $_POST = ['slug' => '', 'synopsis_id' => $synopsisId, 'body' => 'Body text'];
        $this->chapters->store($this->owner, 'p1');
        $_POST = [];

        $revision = $this->db->one(
            "SELECT title, body FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-one'"
        );
        $this->assertNotNull($revision);
        $this->assertSame('Chapter one', (string)$revision['title']);
        $this->assertSame('Body text', (string)$revision['body']);
    }

    public function testProjectUpdatePersistsTitle(): void
    {
        $_POST = ['title' => 'Renamed novel'];
        $response = $this->projects->update($this->owner, 'p1');
        $_POST = [];

        $this->assertSame(302, $response->status());
        $row = $this->db->one("SELECT title FROM projects WHERE id = 'p1'");
        $this->assertSame('Renamed novel', (string)$row['title']);
    }

    public function testOutlineUpdatePersistsField(): void
    {
        $_POST = ['text' => 'One paragraph with setup, three turning points and ending.'];
        $response = $this->projects->outlineUpdate($this->owner, 'p1', 'blurb');
        $_POST = [];

        $this->assertSame(302, $response->status());
        $row = $this->db->one("SELECT blurb FROM projects WHERE id = 'p1'");
        $this->assertSame('One paragraph with setup, three turning points and ending.', (string)$row['blurb']);

        $_POST = ['text' => 'The core of the story in one sentence.'];
        $this->projects->outlineUpdate($this->owner, 'p1', 'idea');
        $_POST = [];
        $row = $this->db->one("SELECT idea FROM projects WHERE id = 'p1'");
        $this->assertSame('The core of the story in one sentence.', (string)$row['idea']);

        $_POST = ['text' => 'A detailed draft of the whole story.'];
        $this->projects->outlineUpdate($this->owner, 'p1', 'synopsis_long');
        $_POST = [];
        $row = $this->db->one("SELECT synopsis_long FROM projects WHERE id = 'p1'");
        $this->assertSame('A detailed draft of the whole story.', (string)$row['synopsis_long']);
    }

    public function testOutlineUpdateRejectsUnknownField(): void
    {
        $response = $this->projects->outlineUpdate($this->owner, 'p1', 'nope');
        $this->assertSame(404, $response->status());
    }

    public function testOutlineEditPageShowsCurrentText(): void
    {
        $this->db->run("UPDATE projects SET idea = 'A heist in reverse.' WHERE id = 'p1'");
        $response = $this->projects->outlineEdit($this->owner, 'p1', 'idea');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('A heist in reverse.', $response->body());
    }

    public function testSetupPageShowsTitleForm(): void
    {
        $response = $this->projects->setup($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Novel Setup', $response->body());
    }

    public function testProjectShowRendersOutlineFields(): void
    {
        $this->db->run(
            "UPDATE projects SET blurb = 'The blurb.', synopsis = '', synopsis_long = '' WHERE id = 'p1'"
        );
        $response = $this->projects->show($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('The blurb.', $response->body());
    }
}
