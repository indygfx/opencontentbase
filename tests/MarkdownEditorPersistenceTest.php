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
use Writing\ChapterController;
use Writing\ChapterSynopsisController;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * The Tiptap editor writes editor.getMarkdown() back into the form's
 * textarea before submit, so the server keeps receiving (and storing)
 * Markdown. These tests assert that the affected form endpoints still
 * persist Markdown (not HTML) and that CSRF/access behaviour is
 * unchanged.
 */
final class MarkdownEditorPersistenceTest extends TestCase
{
    private Database $db;
    private ChapterController $chapters;
    private ChapterSynopsisController $synopses;
    private WritingController $projects;
    private ProjectAccess $access;
    private User $owner;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_mded_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new ContentRenderer($this->db, $registry);
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->access = new ProjectAccess($this->db);
        $this->synopses = new ChapterSynopsisController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access, $renderer);
        $this->chapters = new ChapterController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access, $renderer, $this->synopses);
        $this->projects = new WritingController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $this->access, $renderer);

        foreach ([['o1', 'owner'], ['s1', 'stranger']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $this->stranger = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 's1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db);
        $_POST = [];
    }

    public function testChapterBodyIsStoredAsMarkdown(): void
    {
        $synopsis = $this->createSynopsis('Journey');
        $markdown = "# Title\n\nSome **bold** and *italic* text.\n\n- one\n- two\n";
        $_POST = ['slug' => '', 'synopsis_id' => $synopsis, 'body' => $markdown];
        $this->chapters->store($this->owner, 'p1');

        $stored = (string)$this->db->one(
            "SELECT r.body FROM content_revisions r JOIN content_objects o ON o.id = r.object_id WHERE o.slug = 'journey' ORDER BY r.created_at DESC"
        )['body'];
        $this->assertSame($markdown, $stored);
        $this->assertStringNotContainsString('<', $stored);
    }

    public function testSynopsisSummaryIsStoredAsMarkdown(): void
    {
        $_POST = ['slug' => '', 'title' => 'The robbery', 'summary' => 'They **steal** the *crown*.'];
        $this->synopses->store($this->owner, 'p1');

        $stored = (string)$this->db->one(
            "SELECT summary_text FROM chapter_synopses WHERE slug = 'the-robbery'"
        )['summary_text'];
        $this->assertSame('They **steal** the *crown*.', $stored);
        $this->assertStringNotContainsString('<', $stored);
    }

    public function testOutlineFieldIsStoredAsMarkdown(): void
    {
        $markdown = 'A logline with **emphasis**.';
        $_POST = ['text' => $markdown];
        $this->projects->outlineUpdate($this->owner, 'p1', 'idea');

        $stored = (string)$this->db->one("SELECT idea FROM projects WHERE id = 'p1'")['idea'];
        $this->assertSame($markdown, $stored);
    }

    public function testStoredMarkdownRendersThroughCommonmark(): void
    {
        $registry = new ModuleRegistry();
        $renderer = new ContentRenderer($this->db, $registry);
        $synopsis = $this->createSynopsis('Render me');
        $_POST = ['slug' => '', 'synopsis_id' => $synopsis, 'body' => 'Hello **world**!'];
        $this->chapters->store($this->owner, 'p1');

        $stored = (string)$this->db->one(
            "SELECT r.body FROM content_revisions r JOIN content_objects o ON o.id = r.object_id WHERE o.slug = 'render-me' ORDER BY r.created_at DESC"
        )['body'];
        $html = $renderer->render($stored);
        $this->assertSame('<p>Hello <strong>world</strong>!</p>', $html);
    }

    public function testStrangerCannotUseChapterForm(): void
    {
        $synopsis = $this->createSynopsis('Locked');
        $_POST = ['slug' => '', 'synopsis_id' => $synopsis, 'body' => 'nope'];
        $response = $this->chapters->store($this->stranger, 'p1');
        $this->assertSame(404, $response->status());
        $this->assertSame(
            0,
            (int)$this->db->one("SELECT COUNT(*) AS c FROM content_objects WHERE slug = 'locked'")['c']
        );
    }

    private function createSynopsis(string $title): string
    {
        $_POST = ['slug' => '', 'title' => $title, 'summary' => ''];
        $this->synopses->store($this->owner, 'p1');
        $_POST = [];
        $row = $this->db->one(
            'SELECT id FROM chapter_synopses WHERE title = ?',
            [$title]
        );
        return (string)$row['id'];
    }
}
