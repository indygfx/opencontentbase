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
use Writing\ProjectAccess;
use Writing\StorySlider;
use Writing\WritingController;
use Writing\WritingModule;

final class StorySliderTest extends TestCase
{
    private Database $db;
    private StorySlider $slider;
    private User $owner;
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
        $this->slider = new StorySlider($this->db);
        foreach ([['o1', 'owner'], ['s1', 'stranger']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = $this->user('o1');
        $this->stranger = $this->user('s1');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
    }

    public function testNoSlidesWithoutQualifyingChapters(): void
    {
        $this->createChapter('chapter-1', "One paragraph only.");
        $this->assertSame([], $this->slider->slides($this->owner, 'seed'));
    }

    public function testChapterWithMoreThanThreeParagraphsQualifies(): void
    {
        $body = "First paragraph of the story.\n\nSecond paragraph here.\n\nThird one follows.\n\nAnd the fourth.";
        $this->createChapter('chapter-1', $body);
        $slides = $this->slider->slides($this->owner, 'seed');
        $this->assertCount(1, $slides);
        $this->assertSame('My novel', $slides[0]['project_title']);
        $this->assertSame('chapter-1', $slides[0]['chapter_slug']);
    }

    public function testExcerptIsTruncatedToEightyWords(): void
    {
        $words = [];
        for ($i = 1; $i <= 120; $i++) {
            $words[] = 'word' . $i;
        }
        $body = 'Alpha paragraph one here.' . "\n\n" . implode(' ', $words) . "\n\nBeta three.\n\nGamma four.";
        $this->createChapter('chapter-1', $body);
        $slides = $this->slider->slides($this->owner, 'seed');
        $this->assertCount(1, $slides);
        $text = $slides[0]['text'];
        $count = preg_match_all('/\S+/u', rtrim($text, ' …')) ?? 0;
        $this->assertLessThanOrEqual(80, $count);
    }

    public function testAtMostThreeSlides(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $body = "P one $i.\n\nP two $i.\n\nP three $i.\n\nP four $i.";
            $this->createChapter("chapter-$i", $body);
        }
        $slides = $this->slider->slides($this->owner, 'seed');
        $this->assertCount(3, $slides);
        $keys = [];
        foreach ($slides as $slide) {
            $keys[] = $slide['chapter_slug'];
        }
        $this->assertSame($keys, array_unique($keys));
    }

    public function testSelectionIsStableWithinSession(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $body = "P one $i.\n\nP two $i.\n\nP three $i.\n\nP four $i.";
            $this->createChapter("chapter-$i", $body);
        }
        $first = $this->slider->slides($this->owner, 'session-seed');
        $second = $this->slider->slides($this->owner, 'session-seed');
        $this->assertSame($first, $second);
    }

    public function testDifferentSessionsMayDiffer(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $body = "P one $i.\n\nP two $i.\n\nP three $i.\n\nP four $i.";
            $this->createChapter("chapter-$i", $body);
        }
        $a = $this->slider->slides($this->owner, 'seed-a');
        $b = $this->slider->slides($this->owner, 'seed-b');
        $this->assertNotSame($a, $b);
    }

    public function testOnlyOwnProjects(): void
    {
        $body = "P one.\n\nP two.\n\nP three.\n\nP four.";
        $this->createChapter('chapter-1', $body);
        $this->assertSame([], $this->slider->slides($this->stranger, 'seed'));
    }

    public function testDashboardRendersSlider(): void
    {
        $this->createChapter('chapter-1', "P one.\n\nP two.\n\nP three.\n\nP four.");
        $_COOKIE['cb_csrf'] = str_repeat('a', 64);
        $controller = new WritingController(
            $this->db,
            new \Core\View(dirname(__DIR__)),
            new Csrf($this->db, new Auth($this->db)),
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $response = $controller->index($this->owner);
        $_COOKIE = [];
        $html = (string)$response->body();
        $this->assertStringContainsString('story-slider', $html);
        $this->assertStringContainsString('My novel', $html);
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }

    private function createChapter(string $slug, string $body): void
    {
        $this->db->run(
            "INSERT INTO chapter_synopses (id, project_id, slug, title) VALUES (?, 'p1', ?, ?)",
            ['syn-' . $slug, 'syn-' . $slug, 'Chapter ' . $slug]
        );
        $synopsisId = (string)$this->db->one(
            'SELECT id FROM chapter_synopses WHERE project_id = ? AND slug = ?',
            ['p1', 'syn-' . $slug]
        )['id'];
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id, created_at) VALUES (?, 'chapter', ?, 'o1', ?)",
            [uniqid(), $slug, gmdate('Y-m-d H:i:s')]
        );
        $objectId = (string)$this->db->one(
            "SELECT id FROM content_objects WHERE type = 'chapter' AND slug = ? AND owner_id = 'o1'",
            [$slug]
        )['id'];
        $this->db->run(
            'INSERT INTO chapters (id, project_id, chapter_synopsis_id, slug) VALUES (?, ?, ?, ?)',
            [$objectId, 'p1', $synopsisId, $slug]
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, summary, body, author_id) VALUES (?, ?, ?, ?, ?, ?)',
            [uniqid(), $objectId, 'Chapter ' . $slug, '', $body, 'o1']
        );
    }
}
