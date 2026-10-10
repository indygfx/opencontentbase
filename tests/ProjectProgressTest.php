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
use Writing\ProjectAccess;
use Writing\ProjectProgress;
use Writing\WritingController;
use Writing\WritingModule;

final class ProjectProgressTest extends TestCase
{
    private Database $db;
    private ProjectProgress $progress;
    private WritingController $controller;
    private User $owner;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_prog_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $registry = new ModuleRegistry();
        $renderer = new ContentRenderer($this->db, $registry);
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer, '');
        $registry->register($module);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $access = new ProjectAccess($this->db);
        $this->progress = new ProjectProgress($this->db);
        $this->controller = new WritingController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access, $renderer, '');

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

    public function testWordsCountsWhitespaceTokensAndStripsLinks(): void
    {
        $this->assertSame(0, ProjectProgress::words(''));
        $this->assertSame(3, ProjectProgress::words('one two three'));
        $this->assertSame(1, ProjectProgress::words('[[chapter:foo]] two'));
        $this->assertSame(2, ProjectProgress::words("one\n\ntwo"));
        $this->assertSame(2, ProjectProgress::words('  one   two  '));
    }

    public function testGenreWordTargetFallsBackToGeneral(): void
    {
        $this->assertSame(110000, ProjectProgress::genreWordTarget('Fantasy'));
        $this->assertSame(70000, ProjectProgress::genreWordTarget('Young Adult'));
        $this->assertSame(90000, ProjectProgress::genreWordTarget('General'));
        $this->assertSame(90000, ProjectProgress::genreWordTarget('Unknown genre'));
    }

    public function testEmptyProjectHasZeroProgress(): void
    {
        $result = $this->progress->calculate('p1');
        $this->assertSame(0.0, $result['idea']['ratio']);
        $this->assertSame(0.0, $result['blurb']['ratio']);
        $this->assertSame(0.0, $result['outlines']['ratio']);
        $this->assertSame(0.0, $result['chapters']['ratio']);
        $this->assertSame(0.0, $this->progress->overall('p1'));
    }

    public function testDefaultsApplyWithoutSavedRow(): void
    {
        $this->db->run('DELETE FROM project_targets WHERE project_id = ?', ['p1']);
        $targets = $this->progress->targets('p1');
        $this->assertSame(40, $targets['target_chapters']);
        $this->assertSame(90000, $targets['target_words_total']);
        $this->assertSame(150, $targets['target_words_blurb']);
        $this->assertSame(3, $targets['target_min_characters']);
        $this->assertSame(2, $targets['target_min_relations']);
    }

    public function testIdeaFilledMeansDone(): void
    {
        $this->db->run("UPDATE projects SET idea = 'A heist gone wrong' WHERE id = 'p1'");
        $result = $this->progress->calculate('p1');
        $this->assertSame(1.0, $result['idea']['ratio']);
        $this->assertTrue($result['idea']['done']);
    }

    public function testBlurbRatioCappedAtOne(): void
    {
        $this->db->run(
            "UPDATE projects SET blurb = ? WHERE id = 'p1'",
            [implode(' ', array_fill(0, 500, 'word'))]
        );
        $result = $this->progress->calculate('p1');
        $this->assertSame(1.0, $result['blurb']['ratio']);
        $this->assertTrue($result['blurb']['done']);
    }

    public function testRelationsTargetIsCharactersTimesRelations(): void
    {
        // (a): target = min characters * relations per character = 3 * 2 = 6
        $result = $this->progress->calculate('p1');
        $this->assertSame(6, $result['relations']['target']);

        // three characters with six relations => done
        foreach (['c1', 'c2', 'c3'] as $cid) {
            $this->db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'o1')",
                [$cid, $cid]
            );
            $this->db->run(
                'INSERT INTO characters (id, project_id, slug) VALUES (?, ?, ?)',
                [$cid, 'p1', $cid]
            );
        }
        $pairs = [['c1', 'c2'], ['c1', 'c3'], ['c2', 'c3'], ['c1', 'c2'], ['c1', 'c3'], ['c2', 'c3']];
        foreach ($pairs as $i => [$from, $to]) {
            $this->db->run(
                'INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind) VALUES (?, ?, ?, ?, ?)',
                ['r' . $i, 'p1', $from, $to, 'friend']
            );
        }
        $result = $this->progress->calculate('p1');
        $this->assertSame(1.0, $result['relations']['ratio']);
        $this->assertSame(1, $result['characters']['actual'] === 3 ? 1 : 0);
    }

    public function testChapterWordsComeFromLatestRevision(): void
    {
        $this->createSynopsis('Journey', 's1');
        $this->createChapter('s1', 'one two three four five');
        // a second (newer) revision with fewer words must win
        $objectId = (string)$this->db->one(
            "SELECT id FROM content_objects WHERE slug = 'journey'"
        )['id'];
        $this->db->run(
            "INSERT INTO content_revisions (id, object_id, title, body, author_id, created_at) VALUES (?, ?, ?, ?, ?, '2030-01-01 00:00:00')",
            [\Core\Auth::uuid4(), $objectId, 'Journey', 'one two three', 'o1']
        );
        $result = $this->progress->calculate('p1');
        $this->assertSame(3, $result['chapters']['actual']);
    }

    public function testOverallWeightedSum(): void
    {
        // only idea filled: 5% of the overall progress
        $this->db->run("UPDATE projects SET idea = 'x' WHERE id = 'p1'");
        $this->assertEqualsWithDelta(0.05, $this->progress->overall('p1'), 0.0001);
    }

    public function testUpdateTargetsPersistsAndRedirects(): void
    {
        $_POST = [
            'genre' => 'Fantasy',
            'target_chapters' => '50',
            'target_words_total' => '110000',
            'target_words_blurb' => '200',
            'target_words_synopsis' => '800',
            'target_min_characters' => '4',
            'target_min_relations' => '3',
            'target_backgrounds' => '5',
        ];
        $response = $this->controller->updateTargets($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $targets = $this->progress->targets('p1');
        $this->assertSame(50, $targets['target_chapters']);
        $this->assertSame(110000, $targets['target_words_total']);
        $this->assertSame(4, $targets['target_min_characters']);
        $this->assertSame(3, $targets['target_min_relations']);
        $this->assertSame(
            'Fantasy',
            (string)$this->db->one("SELECT genre FROM projects WHERE id = 'p1'")['genre']
        );
    }

    public function testUpdateTargetsRejectsInvalidNumbers(): void
    {
        $_POST = [
            'genre' => 'General',
            'target_chapters' => '-5',
            'target_words_total' => '90000',
            'target_words_blurb' => '150',
            'target_words_synopsis' => '600',
            'target_min_characters' => '3',
            'target_min_relations' => '2',
            'target_backgrounds' => '4',
        ];
        $response = $this->controller->updateTargets($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $this->assertStringContainsString('positive whole numbers', $response->body());
    }

    public function testUpdateTargetsRejectsStranger(): void
    {
        $_POST = [
            'genre' => 'General',
            'target_chapters' => '40',
            'target_words_total' => '90000',
            'target_words_blurb' => '150',
            'target_words_synopsis' => '600',
            'target_min_characters' => '3',
            'target_min_relations' => '2',
            'target_backgrounds' => '4',
        ];
        $response = $this->controller->updateTargets($this->stranger, 'p1');
        $this->assertSame(403, $response->status());
    }

    private function createSynopsis(string $title, string $slug): void
    {
        $id = \Core\Auth::uuid4();
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter_synopsis', ?, 'o1')",
            [$id, $slug]
        );
        $this->db->run(
            'INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES (?, ?, ?, ?)',
            [$id, 'p1', $slug, $title]
        );
    }

    private function createChapter(string $synopsisId, string $body): void
    {
        $id = \Core\Auth::uuid4();
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'chapter', 'journey', 'o1')",
            [$id]
        );
        $this->db->run(
            'INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES (?, ?, ?, ?)',
            [$id, 'p1', $this->synopsisRowId($synopsisId), 'journey']
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [\Core\Auth::uuid4(), $id, 'Journey', $body, 'o1']
        );
    }

    private function synopsisRowId(string $slug): string
    {
        return (string)$this->db->one('SELECT id FROM chapter_synopses WHERE slug = ?', [$slug])['id'];
    }
}
