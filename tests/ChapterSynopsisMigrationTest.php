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
use Writing\WritingModule;

final class ChapterSynopsisMigrationTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['o1', 'owner', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'Legacy novel')");
        $_POST = [];
    }

    public function testMigrationBackfillsExistingChapters(): void
    {
        $this->insertLegacyChapter('legacy-one', 'The First Battle', 'Armies clash at dawn.');
        $this->insertLegacyChapter('legacy-two', 'The Long Wait', '');

        $this->runWritingMigrations();

        $rows = $this->db->all(
            'SELECT c.slug, s.title, s.summary_text, s.id AS synopsis_id
             FROM chapters c JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             WHERE c.project_id = \'p1\'
             ORDER BY c.slug'
        );
        $this->assertCount(2, $rows);
        $this->assertSame('legacy-one', (string)$rows[0]['slug']);
        $this->assertSame('The First Battle', (string)$rows[0]['title']);
        $this->assertSame('Armies clash at dawn.', (string)$rows[0]['summary_text']);
        $this->assertSame('legacy-two', (string)$rows[1]['slug']);
        $this->assertSame('The Long Wait', (string)$rows[1]['title']);
        $this->assertSame('', (string)$rows[1]['summary_text']);
        foreach ($rows as $row) {
            $this->assertNotSame('', (string)$row['synopsis_id']);
        }
        $this->assertSame(2, (int)$this->db->one('SELECT COUNT(*) AS c FROM chapter_synopses')['c']);
    }

    public function testBackfilledSynopsisIsLinkedAndUnique(): void
    {
        $this->insertLegacyChapter('legacy-one', 'The First Battle', 'Armies clash at dawn.');
        $this->runWritingMigrations();

        $chapter = $this->db->one(
            "SELECT c.chapter_synopsis_id FROM chapters c WHERE c.project_id = 'p1' AND c.slug = 'legacy-one'"
        );
        $this->assertSame(1, (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM chapters WHERE chapter_synopsis_id = ?',
            [(string)$chapter['chapter_synopsis_id']]
        )['c']);
    }

    public function testFreshInstallHasNoSynopsisRowsButConstraint(): void
    {
        $this->runWritingMigrations();
        $this->assertSame(0, (int)$this->db->one('SELECT COUNT(*) AS c FROM chapters')['c']);
        $this->db->run(
            "INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES ('s1', 'p1', 's-one', 'Outline')"
        );
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c1', 'chapter', 'legacy-one', 'o1')"
        );
        $this->db->run(
            "INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c1', 'p1', 's1', 'legacy-one')"
        );
        $caught = false;
        try {
            $this->db->run(
                "INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES ('c2', 'p1', 's1', 'other')"
            );
        } catch (\RuntimeException) {
            $caught = true;
        }
        $this->assertTrue($caught, 'UNIQUE constraint on chapter_synopsis_id must be enforced');
    }

    private function insertLegacyChapter(string $slug, string $title, string $summary): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter', ?, 'o1')",
            [$slug, $slug]
        );
        $this->db->run(
            'INSERT INTO chapters (id, project_id, slug) VALUES (?, \'p1\', ?)',
            [$slug, $slug]
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id) VALUES (?, ?, ?, ?, ?, ?)',
            [Auth::uuid4(), $slug, $title, $summary, 'Legacy prose.', 'o1']
        );
    }

    private function runWritingMigrations(): void
    {
        $base = dirname(__DIR__);
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new ContentRenderer($this->db, $registry);
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
    }
}
