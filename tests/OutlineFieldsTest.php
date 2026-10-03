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
use Writing\WritingController;
use Writing\WritingModule;

final class OutlineFieldsTest extends TestCase
{
    private Database $db;
    private ChapterController $chapters;
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
        $this->chapters = new ChapterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer);
        $this->projects = new WritingController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['o1', 'owner', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->owner = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $_POST = [];
    }

    public function testChapterSummaryIsStoredAndKeptOnUpdate(): void
    {
        $_POST = ['slug' => 'chapter-1', 'title' => 'Chapter one', 'summary' => 'The basic idea of this chapter', 'body' => 'Body text'];
        $this->chapters->store($this->owner, 'p1');
        $_POST = [];

        $revision = $this->db->one(
            "SELECT title, summary, body FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-1'"
        );
        $this->assertNotNull($revision);
        $this->assertSame('The basic idea of this chapter', (string)$revision['summary']);
        $this->assertSame('Body text', (string)$revision['body']);

        $_POST = ['slug' => 'chapter-1', 'title' => 'Chapter one', 'summary' => 'A new idea', 'body' => 'Longer body'];
        $this->chapters->update($this->owner, 'p1', 'chapter-1');
        $_POST = [];

        $latest = $this->db->one(
            "SELECT summary, body FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-1'
             ORDER BY r.created_at DESC, r.rowid DESC LIMIT 1"
        );
        $this->assertSame('A new idea', (string)$latest['summary']);
        $this->assertSame('Longer body', (string)$latest['body']);
    }

    public function testProjectUpdatePersistsOutlineFields(): void
    {
        $_POST = [
            'title' => 'My novel',
            'blurb' => 'One paragraph with setup, three turning points and ending.',
            'synopsis' => "Chapter 1: Departure.\nChapter 2: Turning point.",
            'synopsis_long' => 'Extended synopsis with more detail.',
        ];
        $response = $this->projects->update($this->owner, 'p1');
        $_POST = [];

        $this->assertSame(302, $response->status());
        $row = $this->db->one("SELECT title, blurb, synopsis, synopsis_long FROM projects WHERE id = 'p1'");
        $this->assertSame('One paragraph with setup, three turning points and ending.', (string)$row['blurb']);
        $this->assertSame("Chapter 1: Departure.\nChapter 2: Turning point.", (string)$row['synopsis']);
        $this->assertSame('Extended synopsis with more detail.', (string)$row['synopsis_long']);
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
