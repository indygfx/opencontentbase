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
use Writing\GraphController;
use Writing\ProjectAccess;
use Writing\RelationshipGraph;
use Writing\WritingModule;

/**
 * Weighted circular relationship graph: layout rules, deterministic
 * rendering, edge merging, node variants, empty states and access
 * control for the /graph route.
 */
final class RelationshipGraphTest extends TestCase
{
    private Database $db;
    private GraphController $controller;
    private User $owner;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_graph_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $renderer = new \Core\ContentRenderer($this->db, new \Core\ModuleRegistry());
        $module = new WritingModule($this->db, new \Core\View($base), new Csrf($this->db, $auth), $renderer);
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $access = new ProjectAccess($this->db);
        $this->controller = new GraphController(
            $this->db,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            $access,
            new \Writing\CharacterMentions($this->db),
            new \Writing\RelationshipController($this->db, new \Core\View($base), new Csrf($this->db, $auth), $access)
        );

        foreach ([['o1', 'owner'], ['s1', 'stranger']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = $this->user('o1');
        $this->stranger = $this->user('s1');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller, $this->owner, $this->stranger);
        $_POST = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }

    private function addCharacter(string $id, string $slug, string $title, string $imagePath = ''): void
    {
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'o1')",
            [$id, $slug]
        );
        $this->db->run(
            "INSERT INTO characters (id, project_id, slug, image_path) VALUES (?, 'p1', ?, ?)",
            [$id, $slug, $imagePath]
        );
        $this->db->run(
            'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
            [Auth::uuid4(), $id, $title, '', 'o1']
        );
    }

    private function addRelation(string $from, string $to, string $kind, string $description = ''): void
    {
        $this->db->run(
            'INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind, description)
             VALUES (?, ?, ?, ?, ?, ?)',
            [Auth::uuid4(), 'p1', $from, $to, $kind, $description]
        );
    }

    /** @param list<array{id: string, slug: string, title: string, level: int, has_image: bool}> $characters */
    private function renderGraph(array $characters, array $relations): string
    {
        return (new RelationshipGraph())->render('p1', $characters, $relations);
    }

    public function testProtagonistSitsInCenter(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 9, 'has_image' => false],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 2, 'has_image' => false],
            ],
            []
        );
        // Single protagonist on the inner circle (radius 80 around
        // (450, 350)) sits exactly at (450, 270); the level-2 character
        // sits on the outer ring at (450, 100).
        $this->assertStringContainsString('Jakob · L9', $svg);
        $this->assertStringContainsString('Lena · L2', $svg);
        $this->assertSame(1, substr_count($svg, '<circle cx="450.0" cy="270.0"'));
        $this->assertSame(1, substr_count($svg, '<circle cx="450.0" cy="100.0"'));
    }

    public function testWithoutProtagonistEveryoneStaysOnOuterRing(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => false],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 3, 'has_image' => false],
            ],
            []
        );
        // Outer ring (radius 250): first node at (450, 100), second evenly
        // distributed; nobody sits on the inner protagonist circle.
        $this->assertSame(1, substr_count($svg, '<circle cx="450.0" cy="100.0"'));
        $this->assertStringNotContainsString('cy="270.0"', $svg);
    }

    public function testDeterministicOutputForSameData(): void
    {
        $characters = [
            ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 4, 'has_image' => false],
            ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 8, 'has_image' => true],
            ['id' => 'c', 'slug' => 'tom', 'title' => 'Tom', 'level' => 8, 'has_image' => false],
        ];
        $relations = [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'friend', 'description' => 'childhood'],
            ['from_id' => 'b', 'to_id' => 'a', 'kind' => 'lover', 'description' => 'secret'],
            ['from_id' => 'c', 'to_id' => 'a', 'kind' => 'rival', 'description' => 'duel'],
        ];
        $first = $this->renderGraph($characters, $relations);
        $second = $this->renderGraph($characters, $relations);
        $this->assertSame($first, $second);
    }

    public function testReciprocalPairMergesIntoOneCurvedPath(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => false],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 5, 'has_image' => false],
            ],
            [
                ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'friend', 'description' => 'childhood'],
                ['from_id' => 'b', 'to_id' => 'a', 'kind' => 'lover', 'description' => 'secret'],
            ]
        );
        // One merged edge (Q curve) instead of two separate paths.
        $this->assertSame(1, substr_count($svg, '<path'));
        $this->assertStringContainsString(' Q ', $svg);
        // Both direction labels near their ends.
        $this->assertStringContainsString('>friend</text>', $svg);
        $this->assertStringContainsString('>lover</text>', $svg);
        $this->assertStringNotContainsString('marker-end', $svg);
    }

    public function testOneDirectionalEdgeGetsArrowhead(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => false],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 5, 'has_image' => false],
            ],
            [
                ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'mentor', 'description' => 'wise words'],
            ]
        );
        $this->assertStringContainsString('marker-end="url(#graph-arrow)"', $svg);
        $this->assertStringContainsString('>mentor</text>', $svg);
        $this->assertSame(1, substr_count($svg, '<path'));
    }

    public function testUnknownKindFallsBackToNeutralColor(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => false],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 5, 'has_image' => false],
            ],
            [
                ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'bonded', 'description' => ''],
            ]
        );
        $this->assertStringContainsString('graph-edge-kind-bonded', $svg);
        $this->assertStringContainsString('#9ca3af', $svg);
    }

    public function testConflictTextBecomesTitleTooltip(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => false],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 5, 'has_image' => false],
            ],
            [
                ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'enemy', 'description' => 'old feud'],
            ]
        );
        $this->assertStringContainsString('<title>old feud</title>', $svg);
    }

    public function testSelfReferenceSkippedDefensively(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => false],
            ],
            [
                ['from_id' => 'a', 'to_id' => 'a', 'kind' => 'friend', 'description' => ''],
            ]
        );
        $this->assertStringNotContainsString('<path', $svg);
    }

    public function testImageNodeRendersClippedImagePlainNodeCircle(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 5, 'has_image' => true],
                ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 5, 'has_image' => false],
            ],
            []
        );
        $this->assertStringContainsString('<image', $svg);
        $this->assertStringContainsString('clip-path="url(#graph-clip-a)"', $svg);
        $this->assertStringContainsString('characters/jakob/image', $svg);
        $this->assertStringContainsString('graph-node-circle', $svg);
    }

    public function testNodeLinksToCharacterPageAndLabelContainsLevel(): void
    {
        $svg = $this->renderGraph(
            [
                ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 7, 'has_image' => false],
            ],
            []
        );
        $this->assertStringContainsString('href="/writing/p1/characters/jakob"', $svg);
        $this->assertStringContainsString('Jakob · L7', $svg);
        $this->assertStringContainsString('graph-node-label-bg', $svg);
    }

    public function testEmptyCharacterListRendersNothing(): void
    {
        $this->assertSame('', $this->renderGraph([], []));
    }

    public function testGraphPageEmptyStateForZeroCharacters(): void
    {
        $response = $this->controller->show($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('no characters yet', $response->body());
        $this->assertStringNotContainsString('<svg', $response->body());
    }

    public function testGraphPageSingleCharacterState(): void
    {
        $this->addCharacter('a', 'jakob', 'Jakob');
        $response = $this->controller->show($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Only one character so far', $response->body());
        $this->assertStringContainsString('<svg', $response->body());
    }

    public function testGraphPageRendersSvgWithCharactersAndLevels(): void
    {
        $this->addCharacter('a', 'jakob', 'Jakob', 'a.png');
        $this->addCharacter('b', 'lena', 'Lena');
        $this->addRelation('a', 'b', 'friend', 'childhood');
        $response = $this->controller->show($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('<svg', $response->body());
        $this->assertStringContainsString('Jakob · L0', $response->body());
        $this->assertStringContainsString('Lena · L0', $response->body());
        $this->assertStringContainsString('>friend</text>', $response->body());
        $this->assertStringContainsString('graph-legend', $response->body());
        $this->assertStringContainsString('#relationships', $response->body());
    }

    public function testGraphPageRequiresAccess(): void
    {
        $this->addCharacter('a', 'jakob', 'Jakob');
        $response = $this->controller->show($this->stranger, 'p1');
        $this->assertSame(404, $response->status());
        $this->assertStringNotContainsString('<svg', $response->body());
    }

    public function testGraphPageUnknownStory(): void
    {
        $response = $this->controller->show($this->owner, 'missing');
        $this->assertSame(404, $response->status());
    }
}
