<?php
declare(strict_types=1);

namespace Tests\Writing;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use PHPUnit\Framework\TestCase;
use Writing\CharacterMentions;
use Writing\WritingModule;

/**
 * Mention-based character weighting: case-insensitive counting of
 * character titles in chapter prose (latest revision only), the
 * level formula, and on-demand cache invalidation.
 */
final class CharacterMentionsTest extends TestCase
{
    private Database $db;
    private CharacterMentions $mentions;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_mentions_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $renderer = new \Core\ContentRenderer($this->db, new \Core\ModuleRegistry());
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->mentions = new CharacterMentions($this->db);

        $this->db->run("INSERT INTO users (id, username, password, role) VALUES ('u1', 'author', 'x', 'user')");
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'u1', 'My novel')");

        foreach ([['k1', 'jakob', 'Jakob'], ['k2', 'lena', 'Lena']] as [$id, $slug, $title]) {
            $this->db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'u1')",
                [$id, $slug]
            );
            $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES (?, 'p1', ?)", [$id, $slug]);
            $this->db->run(
                'INSERT INTO content_revisions (id, object_id, title, body, author_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [Auth::uuid4(), $id, $title, '', 'u1', '2030-01-01 00:00:01']
            );
        }

        $this->addChapter('ch1', 'one', 'Chapter One', 'Jakob enters. JAKOB waves at lena.', '2030-01-01 00:00:01');
        $this->addChapter(
            'ch2',
            'two',
            'Chapter Two',
            'Lena smiles because Jakob is there. Lena knows Jakob.',
            '2030-01-01 00:00:01'
        );
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->mentions);
        $_POST = [];
    }

    private function addChapter(string $id, string $slug, string $title, string $body, string $createdAt): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter_synopsis', ?, 'u1')",
            [$id . '-syn', $slug]
        );
        $this->db->run(
            "INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES (?, 'p1', ?, ?)",
            [$id . '-syn', $slug, $title]
        );
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter', ?, 'u1')",
            [$id, $slug]
        );
        $this->db->run(
            "INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES (?, 'p1', ?, ?)",
            [$id, $id . '-syn', $slug]
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Auth::uuid4(), $id, $title, $body, 'u1', $createdAt]
        );
    }

    private function addRevision(string $chapterId, string $body, string $createdAt): void
    {
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Auth::uuid4(), $chapterId, 'Updated', $body, 'u1', $createdAt]
        );
    }

    public function testCountsAreCaseInsensitive(): void
    {
        $counts = $this->mentions->recount('p1');
        // ch1: Jakob x2, Lena x1; ch2: Jakob x2, Lena x2.
        $this->assertSame(4, $counts['k1']);
        $this->assertSame(3, $counts['k2']);
    }

    public function testOnlyLatestRevisionCounts(): void
    {
        $this->addRevision('ch1', 'Lena. Lena. Lena. Lena.', '2030-01-01 00:00:02');
        $counts = $this->mentions->recount('p1');
        // Latest ch1 revision: 4 Lena mentions + 2 from ch2 = 6;
        // the old ch1 revision (Jakob x2, Lena x1) no longer counts.
        $this->assertSame(6, $counts['k2']);
        $this->assertSame(2, $counts['k1']);
    }

    public function testCountsUseCacheWithoutProseChange(): void
    {
        $this->mentions->recount('p1');
        // Corrupt the prose in place; created_at and chapter count stay
        // the same, so the fingerprint does not change and the cache wins.
        $this->db->run(
            'UPDATE content_revisions SET body = ? WHERE object_id = ?',
            ['Jakob Jakob Jakob Jakob Jakob Jakob', 'ch2']
        );
        $counts = $this->mentions->counts('p1');
        $this->assertSame(4, $counts['k1']);
        $this->assertSame(3, $counts['k2']);
    }

    public function testCacheInvalidatesOnProseChange(): void
    {
        $this->mentions->recount('p1');
        $this->addRevision('ch2', 'Jakob Jakob Jakob Jakob Jakob.', '2030-01-01 00:00:02');
        $counts = $this->mentions->counts('p1');
        // ch2 now contributes 5 Jakob mentions, ch1 still 2 => 7.
        $this->assertSame(7, $counts['k1']);
    }

    public function testCacheInvalidatesOnChapterCountChange(): void
    {
        $this->mentions->recount('p1');
        $this->addChapter('ch3', 'three', 'Chapter Three', 'Lena Lena', '2030-01-01 00:00:01');
        $counts = $this->mentions->counts('p1');
        $this->assertSame(5, $counts['k2']);
    }

    public function testLevelFormulaBoundaries(): void
    {
        $this->assertSame(0, CharacterMentions::levelFor(0));
        $this->assertSame(0, CharacterMentions::levelFor(-5));
        $this->assertSame(2, CharacterMentions::levelFor(1));    // round(sqrt(1) * 1.5) = round(1.5) = 2
        $this->assertSame(3, CharacterMentions::levelFor(5));    // round(sqrt(5) * 1.5) = round(3.35) = 3
        $this->assertSame(10, CharacterMentions::levelFor(45));   // round(sqrt(45) * 1.5) = round(10.06) = 10
        $this->assertSame(10, CharacterMentions::levelFor(1000)); // capped at 10
        $this->assertSame(10, CharacterMentions::levelFor(1000000));
    }

    public function testLevelsForStory(): void
    {
        // 45 mentions of Jakob -> level 10; none of Lena -> level 0.
        $this->addRevision(
            'ch1',
            str_repeat('Jakob ', 45),
            '2030-01-01 00:00:02'
        );
        $levels = $this->mentions->levels('p1');
        $this->assertSame(10, $levels['k1']);
        $this->assertSame(0, $levels['k2']);
    }

    public function testEmptyStoryYieldsEmptyCounts(): void
    {
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p2', 'u1', 'Empty')");
        $this->assertSame([], $this->mentions->counts('p2'));
    }

    public function testCharacterWithoutMentionsHasZero(): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('k3', 'character', 'silent', 'u1')"
        );
        $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES ('k3', 'p1', 'silent')");
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [Auth::uuid4(), 'k3', 'Silent', '', 'u1']
        );
        $counts = $this->mentions->recount('p1');
        $this->assertSame(0, $counts['k3']);
        $this->assertSame(4, $counts['k1']);
    }
}
