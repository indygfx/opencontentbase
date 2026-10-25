<?php
declare(strict_types=1);

namespace Tests\Pages;

use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\ModuleRegistry;
use Core\ObjectDeleter;
use Core\User;
use Pages\PagesController;
use Pages\PagesModule;
use PHPUnit\Framework\TestCase;

/**
 * Revision history for pages (admin-only): newest-first list, inline
 * diff against the predecessor, and restore that creates a NEW revision
 * instead of rewriting history. No compare form for pages.
 */
final class PagesRevisionHistoryTest extends TestCase
{
    private Database $db;
    private PagesController $controller;
    private User $admin;
    private User $user;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_pagesrev_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new \Core\Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new \Core\ContentRenderer($this->db, $registry);
        $module = new PagesModule(
            $this->db,
            new \Core\View($base),
            $renderer,
            new ObjectDeleter($this->db, $registry),
            new Csrf($this->db, $auth)
        );
        (new Migrator($this->db))->migrate('pages', $module->migrations());
        $this->controller = new PagesController(
            $this->db,
            new \Core\View($base),
            $renderer,
            new ObjectDeleter($this->db, $registry),
            new Csrf($this->db, $auth)
        );

        foreach ([['a1', 'admin', 'admin'], ['u1', 'user', 'user']] as [$id, $name, $role]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), $role]
            );
        }
        $this->admin = $this->user('a1');
        $this->user = $this->user('u1');
        $_POST = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller);
        $_POST = [];
        $_GET = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one('SELECT id, username, role FROM users WHERE id = ?', [$id]));
    }

    /** Creates a page with several body edits; returns revision ids oldest-first. */
    private function pageWithRevisions(array $bodies): array
    {
        $first = true;
        foreach ($bodies as $body) {
            $_POST = ['slug' => 'about', 'title' => 'About', 'body' => $body];
            if ($first) {
                $this->controller->store($this->admin);
                $first = false;
            } else {
                $this->controller->update('about', $this->admin);
            }
            $_POST = [];
        }
        $objectId = (string)$this->db->one(
            "SELECT p.object_id FROM pages p JOIN content_objects o ON o.id = p.object_id WHERE o.slug = 'about'"
        )['object_id'];
        return array_column($this->db->all(
            'SELECT id FROM content_revisions WHERE object_id = ? ORDER BY created_at ASC, rowid ASC',
            [$objectId]
        ), 'id');
    }

    public function testListNewestFirstWithAuthorAndCurrentBadge(): void
    {
        $revisionIds = $this->pageWithRevisions(["one\nold", "one\nnew"]);
        $response = $this->controller->revisions('about', $this->admin);
        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertNotFalse(strpos($body, $revisionIds[1]));
        $this->assertNotFalse(strpos($body, $revisionIds[0]));
        $this->assertLessThan(strpos($body, $revisionIds[0]), strpos($body, $revisionIds[1]));
        $this->assertStringContainsString('admin', $body);
        $this->assertSame(1, substr_count($body, t('pages', 'history_current')));
        // No compare form on the pages module.
        $this->assertStringNotContainsString('history_compare_from', $body);
    }

    public function testListUnknownPage404(): void
    {
        $this->assertSame(404, $this->controller->revisions('missing', $this->admin)->status());
    }

    public function testDiffAgainstPredecessor(): void
    {
        $revisionIds = $this->pageWithRevisions(["one\nold", "one\nnew"]);
        $response = $this->controller->revisionDiff('about', $revisionIds[1], $this->admin);
        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertSame(1, substr_count($body, 'diff-line diff-added'));
        $this->assertSame(1, substr_count($body, 'diff-line diff-removed'));
        $this->assertStringContainsString('+ new', $body);
        $this->assertStringContainsString('- old', $body);
    }

    public function testOldestRevisionDiffsWithEmptyText(): void
    {
        $revisionIds = $this->pageWithRevisions(["alpha\nbeta"]);
        $response = $this->controller->revisionDiff('about', $revisionIds[0], $this->admin);
        $this->assertSame(200, $response->status());
        $body = $response->body();
        $this->assertSame(2, substr_count($body, 'diff-line diff-added'));
        $this->assertSame(0, substr_count($body, 'diff-line diff-removed'));
    }

    public function testDiffUnknownRevision404(): void
    {
        $this->pageWithRevisions(['body']);
        $this->assertSame(404, $this->controller->revisionDiff('about', 'bogus', $this->admin)->status());
    }

    public function testDiffForeignRevisionId404(): void
    {
        $revisionIds = $this->pageWithRevisions(['mine']);
        // A revision that belongs to another page.
        $_POST = ['slug' => 'other', 'title' => 'Other', 'body' => 'foreign'];
        $this->controller->store($this->admin);
        $_POST = [];
        $foreignId = (string)$this->db->one(
            "SELECT r.id FROM content_revisions r
             JOIN content_objects o ON o.id = r.object_id
             WHERE o.type = 'page' AND o.slug = 'other'"
        )['id'];
        $this->assertSame(404, $this->controller->revisionDiff('about', $foreignId, $this->admin)->status());
    }

    public function testRestoreCreatesNewRevisionAuthoredByRestorer(): void
    {
        $revisionIds = $this->pageWithRevisions(["original text", "changed text"]);
        $objectId = (string)$this->db->one(
            "SELECT p.object_id FROM pages p JOIN content_objects o ON o.id = p.object_id WHERE o.slug = 'about'"
        )['object_id'];
        $countBefore = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];

        $response = $this->controller->restoreRevision('about', $revisionIds[0], $this->admin);
        $this->assertSame(302, $response->status());

        $countAfter = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];
        $this->assertSame($countBefore + 1, $countAfter, 'restore must create a new revision');
        // Old rows intact, new revision has restored content + restorer.
        $this->assertSame('original text', (string)$this->db->one(
            'SELECT body FROM content_revisions WHERE id = ?', [$revisionIds[0]]
        )['body']);
        $latest = $this->db->one(
            'SELECT body, author_id FROM content_revisions WHERE object_id = ? ORDER BY created_at DESC, rowid DESC LIMIT 1',
            [$objectId]
        );
        $this->assertSame('original text', (string)$latest['body']);
        $this->assertSame('a1', (string)$latest['author_id']);
    }

    public function testRestoreLatestIsNoOp(): void
    {
        $revisionIds = $this->pageWithRevisions(["one", "two"]);
        $objectId = (string)$this->db->one(
            "SELECT p.object_id FROM pages p JOIN content_objects o ON o.id = p.object_id WHERE o.slug = 'about'"
        )['object_id'];
        $countBefore = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];
        $response = $this->controller->restoreRevision('about', $revisionIds[1], $this->admin);
        $this->assertSame(302, $response->status());
        $this->assertStringContainsString('notice', $response->header('Location') ?? '');
        $countAfter = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM content_revisions WHERE object_id = ?', [$objectId]
        )['c'];
        $this->assertSame($countBefore, $countAfter, 'restoring the latest revision must not create rows');
    }

    public function testRestoreUnknownRevision404(): void
    {
        $this->pageWithRevisions(['one']);
        $this->assertSame(404, $this->controller->restoreRevision('about', 'bogus', $this->admin)->status());
    }
}
