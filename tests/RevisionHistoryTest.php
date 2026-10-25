<?php
declare(strict_types=1);

namespace Tests\Writing;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\BackgroundController;
use Writing\ChapterController;
use Writing\ChapterSynopsisController;
use Writing\CharacterController;
use Writing\ProjectAccess;
use Writing\RevisionController;
use Writing\WritingModule;

/**
 * Revision history for Writing objects (chapters, characters,
 * backgrounds): newest-first list with author and +/- stats, inline
 * diff against the predecessor, arbitrary compare, and restore that
 * creates a NEW revision instead of rewriting history.
 */
final class RevisionHistoryTest extends TestCase
{
    private Database $db;
    private RevisionController $controller;
    private ChapterController $chapters;
    private CharacterController $characters;
    private BackgroundController $backgrounds;
    private ChapterSynopsisController $synopses;
    private User $owner;
    private User $member;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_revisions_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new \Core\ContentRenderer($this->db, $registry);
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $access = new ProjectAccess($this->db);
        $csrf = new Csrf($this->db, $auth);
        $this->synopses = new ChapterSynopsisController($this->db, new \Core\View($base), $csrf, $access, $renderer);
        $this->chapters = new ChapterController($this->db, new \Core\View($base), $csrf, $access, $renderer, $this->synopses);
        $this->characters = new CharacterController($this->db, new \Core\View($base), $csrf, $access, $renderer, $base);
        $this->backgrounds = new BackgroundController($this->db, new \Core\View($base), $csrf, $access, $renderer);
        $this->controller = new RevisionController($this->db, new \Core\View($base), $csrf, $access);

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
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller, $this->chapters, $this->characters, $this->backgrounds);
        $_POST = [];
        $_GET = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one('SELECT id, username, role FROM users WHERE id = ?', [$id]));
    }

    /** Creates a chapter with several body edits; returns [objectId, revisionIds oldest-first]. */
    private function chapterWithRevisions(array $bodies): array
    {
        $_POST = ['title' => 'Chapter one', 'summary' => ''];
        $this->synopses->store($this->owner, 'p1');
        $_POST = [];
        $synopsisId = (string)$this->db->one(
            'SELECT id FROM chapter_synopses WHERE project_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 1',
            ['p1']
        )['id'];

        $first = true;
        foreach ($bodies as $body) {
            if ($first) {
                $_POST = ['slug' => 'chapter-1', 'synopsis_id' => $synopsisId, 'body' => $body];
                $this->chapters->store($this->owner, 'p1');
                $first = false;
            } else {
                $_POST = ['slug' => 'chapter-1', 'synopsis_id' => $synopsisId, 'body' => $body];
                $this->chapters->update($this->owner, 'p1', 'chapter-1');
            }
            $_POST = [];
        }
        $objectId = (string)$this->db->one(
            "SELECT o.id FROM content_objects o JOIN chapters c ON c.id = o.id
             WHERE o.type = 'chapter' AND o.slug = 'chapter-1' AND c.project_id = 'p1'"
        )['id'];
        $revisionIds = array_column($this->db->all(
            'SELECT id FROM content_revisions WHERE object_id = ? ORDER BY created_at ASC, rowid ASC',
            [$objectId]
        ), 'id');
        return [$objectId, $revisionIds];
    }

    public function testListNewestFirstWithAuthorAndStats(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["line one\nline two", "line one\nline TWO\nline three"]);
        $response = $this->controller->index($this->owner, 'p1', 'chapter', 'chapter-1');
        $this->assertSame(200, $response->status());
        $body = $response->body();
        // Newest-first: the newest revision link appears before the older one.
        $this->assertNotFalse(strpos($body, $revisionIds[1]), 'newest revision must be linked');
        $this->assertNotFalse(strpos($body, $revisionIds[0]), 'oldest revision must be linked');
        $this->assertLessThan(
            strpos($body, $revisionIds[0]),
            strpos($body, $revisionIds[1])
        );
        // Author column.
        $this->assertStringContainsString('owner', $body);
        // Current badge appears exactly once.
        $this->assertSame(1, substr_count($body, t('writing', 'history_current')));
        // Both rows show +/- stats; the newest row shows a dash instead.
        $this->assertSame(2, substr_count($body, 'diffstat-added'));
    }

    public function testListStrangerGets404(): void
    {
        $this->chapterWithRevisions(['body']);
        $response = $this->controller->index($this->stranger, 'p1', 'chapter', 'chapter-1');
        $this->assertSame(404, $response->status());
    }

    public function testListUnknownObject404(): void
    {
        $this->assertSame(404, $this->controller->index($this->owner, 'p1', 'chapter', 'missing')->status());
    }

    public function testListUnknownType404(): void
    {
        // The controller rejects types without a route even if called directly.
        $this->assertSame(404, $this->controller->index($this->owner, 'p1', 'synopsis', 'x')->status());
    }

    public function testDiffAgainstPredecessor(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["line one\nline two", "line one\nline TWO"]);
        $response = $this->controller->show($this->owner, 'p1', 'chapter', 'chapter-1', $revisionIds[1]);
        $this->assertSame(200, $response->status());
        $body = $response->body();
        // Rendered lines (space-separated classes, unlike the stylesheet).
        $this->assertSame(1, substr_count($body, 'diff-line diff-added'));
        $this->assertSame(1, substr_count($body, 'diff-line diff-removed'));
        $this->assertStringContainsString('+ line TWO', $body);
        $this->assertStringContainsString('- line two', $body);
        $this->assertStringContainsString('  line one', $body);
    }

    public function testOldestRevisionDiffsWithEmptyText(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["first content\nsecond line"]);
        $response = $this->controller->show($this->owner, 'p1', 'chapter', 'chapter-1', $revisionIds[0]);
        $this->assertSame(200, $response->status());
        $body = $response->body();
        // Everything is "added" when there is no predecessor; no
        // rendered removed lines (the stylesheet class does not count).
        $this->assertSame(2, substr_count($body, 'diff-line diff-added'));
        $this->assertSame(0, substr_count($body, 'diff-line diff-removed'));
        $this->assertStringContainsString('+ first content', $body);
        $this->assertStringContainsString('+ second line', $body);
    }

    public function testDiffUnknownRevisionId404(): void
    {
        $this->chapterWithRevisions(['body']);
        $this->assertSame(404, $this->controller->show($this->owner, 'p1', 'chapter', 'chapter-1', 'nope')->status());
    }

    public function testDiffForeignRevisionId404(): void
    {
        // A revision of another object must not resolve.
        $this->chapterWithRevisions(['body']);
        $foreign = Auth::uuid4();
        $this->db->run(
            'INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, ?, ?, ?)',
            [$foreign, 'background', 'other', 'o1']
        );
        $this->db->run("INSERT INTO backgrounds (id, project_id, slug) VALUES (?, 'p1', 'other')", [$foreign]);
        $foreignRevision = Auth::uuid4();
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [$foreignRevision, $foreign, 'Other', 'x', 'o1']
        );
        $this->assertSame(
            404,
            $this->controller->show($this->owner, 'p1', 'chapter', 'chapter-1', $foreignRevision)->status()
        );
    }

    public function testCompareTwoRevisions(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["a\nb\nc", "a\nB\nc", "a\nB\nC"]);
        $_GET = ['from' => $revisionIds[0], 'to' => $revisionIds[2]];
        $response = $this->controller->compare($this->owner, 'p1', 'chapter', 'chapter-1');
        $this->assertSame(200, $response->status());
        // "a\nb\nc" -> "a\nB\nC": two lines replaced (b->B, c->C).
        $this->assertSame(2, substr_count($response->body(), 'diff-line diff-added'));
        $this->assertSame(2, substr_count($response->body(), 'diff-line diff-removed'));
        $this->assertStringContainsString('+ B', $response->body());
        $this->assertStringContainsString('+ C', $response->body());
        $this->assertStringContainsString('- b', $response->body());
        $this->assertStringContainsString('- c', $response->body());
        $_GET = [];
    }

    public function testCompareWithForeignId404(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["a"]);
        $_GET = ['from' => $revisionIds[0], 'to' => 'not-a-revision'];
        $this->assertSame(404, $this->controller->compare($this->owner, 'p1', 'chapter', 'chapter-1')->status());
        $_GET = [];
    }

    public function testCompareWithoutParams404(): void
    {
        $this->chapterWithRevisions(['a']);
        $_GET = [];
        $this->assertSame(404, $this->controller->compare($this->owner, 'p1', 'chapter', 'chapter-1')->status());
    }

    public function testCompareStranger404(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["a\nb", "a\nc"]);
        $_GET = ['from' => $revisionIds[0], 'to' => $revisionIds[1]];
        $this->assertSame(404, $this->controller->compare($this->stranger, 'p1', 'chapter', 'chapter-1')->status());
        $_GET = [];
    }

    public function testRestoreCreatesNewRevisionAuthoredByRestorer(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["original text", "changed text"]);
        $countBefore = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];

        $_POST = ['_csrf' => 'irrelevant'];
        $response = $this->controller->restore($this->member, 'p1', 'chapter', 'chapter-1', $revisionIds[0]);
        $this->assertSame(302, $response->status());
        $_POST = [];

        $countAfter = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];
        $this->assertSame($countBefore + 1, $countAfter, 'restore must create a new revision');
        // Old rows intact.
        $oldRow = $this->db->one('SELECT body FROM content_revisions WHERE id = ?', [$revisionIds[0]]);
        $this->assertSame('original text', (string)$oldRow['body']);
        // The new revision carries the restored content and the restorer as author.
        $latest = $this->db->one(
            'SELECT body, author_id FROM content_revisions WHERE object_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 1',
            [$objectId]
        );
        $this->assertSame('original text', (string)$latest['body']);
        $this->assertSame('m1', (string)$latest['author_id']);
    }

    public function testRestoreLatestIsNoOpRedirect(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["one", "two"]);
        $countBefore = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];
        $_POST = ['_csrf' => 'x'];
        $response = $this->controller->restore($this->owner, 'p1', 'chapter', 'chapter-1', $revisionIds[1]);
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('notice', $response->header('Location') ?? '');
        $countAfter = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];
        $this->assertSame($countBefore, $countAfter, 'restoring the latest revision must not create rows');
        $_POST = [];
    }

    public function testRestoreStrangerDenied(): void
    {
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["one", "two"]);
        $_POST = ['_csrf' => 'x'];
        $response = $this->controller->restore($this->stranger, 'p1', 'chapter', 'chapter-1', $revisionIds[0]);
        $this->assertSame(404, $response->status());
        $_POST = [];
    }

    public function testRestoreOnlyForEditors(): void
    {
        // canEdit covers owner and members; strangers never reach this
        // branch (App additionally enforces CSRF for real POST routes).
        [$objectId, $revisionIds] = $this->chapterWithRevisions(["one", "two"]);
        $_POST = [];
        $response = $this->controller->restore($this->owner, 'p1', 'chapter', 'chapter-1', $revisionIds[0]);
        $this->assertSame(302, $response->status());
    }

    public function testRestoreUnknownRevision404(): void
    {
        $this->chapterWithRevisions(["one"]);
        $_POST = ['_csrf' => 'x'];
        $this->assertSame(404, $this->controller->restore($this->owner, 'p1', 'chapter', 'chapter-1', 'missing')->status());
        $_POST = [];
    }

    public function testHistoryWorksForCharactersAndBackgrounds(): void
    {
        // Character
        $_POST = ['slug' => 'hero', 'title' => 'Hero', 'body' => 'Brave'];
        $this->characters->store($this->owner, 'p1');
        $_POST = ['slug' => 'hero', 'title' => 'Hero', 'body' => 'Brave and strong'];
        $this->characters->update($this->owner, 'p1', 'hero');
        $_POST = [];
        $this->assertSame(200, $this->controller->index($this->owner, 'p1', 'character', 'hero')->status());

        // Background
        $_POST = ['slug' => 'world', 'title' => 'World', 'body' => 'Old'];
        $this->backgrounds->store($this->owner, 'p1');
        $_POST = ['slug' => 'world', 'title' => 'World', 'body' => 'Ancient'];
        $this->backgrounds->update($this->owner, 'p1', 'world');
        $_POST = [];
        $this->assertSame(200, $this->controller->index($this->owner, 'p1', 'background', 'world')->status());
    }
}
