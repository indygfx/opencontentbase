<?php
declare(strict_types=1);

namespace Tests\Writing;

use Core\Auth;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\ProjectAccess;
use Writing\RelationshipController;
use Writing\RelationshipGraph;
use Writing\Stances;
use Writing\WritingModule;

/**
 * Stances as the single source of truth for the emotional layer of a
 * relation: group mapping with neutral fallback, CSS class resolution,
 * stance validation in the controller and stance-driven graph colors.
 */
final class StancesTest extends TestCase
{
    private Database $db;
    private RelationshipController $controller;
    private User $owner;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_stances_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $auth = new Auth($this->db);
        $module = new WritingModule(
            $this->db,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            new \Core\ContentRenderer($this->db, new \Core\ModuleRegistry())
        );
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        $this->controller = new RelationshipController(
            $this->db,
            new \Core\View($base),
            new Csrf($this->db, $auth),
            new ProjectAccess($this->db)
        );

        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['o1', 'owner', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->owner = User::fromRow($this->db->one(
            'SELECT id, username, role FROM users WHERE id = ?',
            ['o1']
        ));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        foreach ([['c1', 'hero'], ['c2', 'villain']] as [$id, $slug]) {
            $this->db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'o1')",
                [$id, $slug]
            );
            $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES (?, 'p1', ?)", [$id, $slug]);
            $this->db->run(
                'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
                [Auth::uuid4(), $id, ucfirst($slug), '', 'o1']
            );
        }
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller, $this->owner);
        $_POST = [];
    }

    public function testGroupForEveryListedStance(): void
    {
        foreach (Stances::STANCE_GROUPS as $stance => $group) {
            $this->assertSame($group, Stances::groupFor($stance), "stance {$stance}");
        }
    }

    public function testGroupForUnknownOrEmptyStanceFallsBackToNeutral(): void
    {
        $this->assertSame('neutral', Stances::groupFor('legacy_feeling'));
        $this->assertSame('neutral', Stances::groupFor(''));
    }

    public function testAllStancesMatchesGroupKeys(): void
    {
        $this->assertSame(array_keys(Stances::STANCE_GROUPS), Stances::allStances());
        $this->assertContains('love', Stances::allStances());
        $this->assertContains('hatred', Stances::allStances());
    }

    public function testStancesForGroup(): void
    {
        $this->assertSame(['love', 'passion', 'devotion'], Stances::stancesForGroup('love'));
        $this->assertSame(['hatred', 'resentment', 'jealousy', 'contempt'], Stances::stancesForGroup('hostility'));
        $this->assertSame(['indifference', 'complicated'], Stances::stancesForGroup('neutral'));
        $this->assertSame([], Stances::stancesForGroup('nonexistent'));
    }

    public function testGroupsAlwaysIncludeNeutralAndAreDuplicateFree(): void
    {
        $groups = Stances::groups();
        $this->assertContains('neutral', $groups);
        $this->assertSame(array_values(array_unique($groups)), $groups);
        foreach (Stances::STANCE_GROUPS as $group) {
            $this->assertContains($group, $groups);
        }
    }

    public function testGroupCountStaysAtSeven(): void
    {
        $this->assertCount(7, Stances::groups());
        $this->assertLessThanOrEqual(7, count(Stances::groups()));
    }

    public function testClassForGroupAndFallback(): void
    {
        $this->assertSame('rel-love', Stances::classForGroup('love'));
        $this->assertSame('rel-hostility', Stances::classForGroup('hostility'));
        $this->assertSame('rel-neutral', Stances::classForGroup('unknown-group'));
        $this->assertSame('rel-love', Stances::classFor('love'));
        $this->assertSame('rel-neutral', Stances::classFor('legacy_feeling'));
        $this->assertSame('rel-neutral', Stances::classFor(''));
    }

    public function testEveryGroupHasACssClass(): void
    {
        foreach (Stances::groups() as $group) {
            $this->assertArrayHasKey($group, Stances::CLASSES, "group {$group}");
            $this->assertNotSame('', Stances::CLASSES[$group]);
        }
    }

    public function testValidationAcceptsEveryListedStance(): void
    {
        foreach (Stances::allStances() as $i => $stance) {
            $this->db->run('DELETE FROM character_relations');
            $_POST = [
                'from_character_id' => 'c1',
                'to_character_id' => 'c2',
                'kind' => 'kin',
                'stance' => $stance,
                'description' => '',
            ];
            $response = $this->controller->store($this->owner, 'p1');
            $this->assertSame(302, $response->status(), "stance {$stance} must be accepted");
            $row = $this->db->one('SELECT stance FROM character_relations');
            $this->assertSame($stance, (string)$row['stance']);
        }
    }

    public function testValidationAllowsEmptyStance(): void
    {
        $_POST = [
            'from_character_id' => 'c1',
            'to_character_id' => 'c2',
            'kind' => 'kin',
            'stance' => '',
            'description' => '',
        ];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $row = $this->db->one('SELECT stance FROM character_relations');
        $this->assertNull($row['stance']);
    }

    public function testValidationAllowsMissingStanceKey(): void
    {
        $_POST = [
            'from_character_id' => 'c1',
            'to_character_id' => 'c2',
            'kind' => 'kin',
            'description' => '',
        ];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $row = $this->db->one('SELECT stance FROM character_relations');
        $this->assertNull($row['stance']);
    }

    public function testUnknownStanceRejected(): void
    {
        $_POST = [
            'from_character_id' => 'c1',
            'to_character_id' => 'c2',
            'kind' => 'kin',
            'stance' => 'hijacked',
            'description' => '',
        ];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $this->assertNull($this->db->one('SELECT id FROM character_relations'));
    }

    public function testUpdateChangesStance(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'kin', 'stance' => 'love'];
        $this->controller->store($this->owner, 'p1');
        $relationId = (string)$this->db->one('SELECT id FROM character_relations')['id'];
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'kin', 'stance' => 'hatred', 'description' => ''];
        $response = $this->controller->update($this->owner, 'p1', $relationId);
        $this->assertSame(302, $response->status());
        $row = $this->db->one('SELECT stance FROM character_relations WHERE id = ?', [$relationId]);
        $this->assertSame('hatred', (string)$row['stance']);
    }

    public function testUpdateCanClearStance(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'kin', 'stance' => 'love'];
        $this->controller->store($this->owner, 'p1');
        $relationId = (string)$this->db->one('SELECT id FROM character_relations')['id'];
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'kin', 'stance' => '', 'description' => ''];
        $response = $this->controller->update($this->owner, 'p1', $relationId);
        $this->assertSame(302, $response->status());
        $row = $this->db->one('SELECT stance FROM character_relations WHERE id = ?', [$relationId]);
        $this->assertNull($row['stance']);
    }

    public function testStanceIsDirectional(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'spouse', 'stance' => 'love'];
        $this->controller->store($this->owner, 'p1');
        $_POST = ['from_character_id' => 'c2', 'to_character_id' => 'c1', 'kind' => 'spouse', 'stance' => 'hatred'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $rows = $this->db->all('SELECT from_character_id, stance FROM character_relations ORDER BY from_character_id');
        $this->assertSame('love', (string)$rows[0]['stance']);
        $this->assertSame('hatred', (string)$rows[1]['stance']);
    }

    public function testGraphEdgeClassFollowsStance(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'kin', 'stance' => 'hatred', 'description' => 'feud'],
        ]);
        $this->assertStringContainsString('rel-hostility', $svg);
        // Label still shows the structural role kind (translated when a
        // Translator is active, raw key otherwise).
        $this->assertStringContainsString('>' . t('writing', 'kind_kin') . '</text>', $svg);
    }

    public function testGraphEdgeLabelColorFollowsStance(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'friend', 'stance' => 'love', 'description' => ''],
        ]);
        $this->assertStringContainsString('graph-edge-label rel-love', $svg);
        $this->assertStringContainsString('>' . t('writing', 'kind_friend') . '</text>', $svg);
    }

    public function testGraphNullStanceRendersNeutralClass(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'friend', 'stance' => null, 'description' => ''],
        ]);
        $this->assertStringContainsString('rel-neutral', $svg);
    }

    public function testGraphPreStanceRowWithoutStanceKeyRendersNeutral(): void
    {
        // A relation row written before migration v15 has no stance value;
        // the graph must render it with the neutral class without errors.
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'friend', 'description' => 'old row'],
        ]);
        $this->assertStringContainsString('rel-neutral', $svg);
        $this->assertStringContainsString('>' . t('writing', 'kind_friend') . '</text>', $svg);
    }

    public function testGraphTooltipContainsStanceText(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'kin', 'stance' => 'jealousy', 'description' => 'old feud'],
        ]);
        $this->assertStringContainsString('old feud - ' . t('writing', 'stance_jealousy'), $svg);
    }

    public function testGraphTooltipWithOnlyStance(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'kin', 'stance' => 'awe', 'description' => ''],
        ]);
        $this->assertStringContainsString('<title>' . t('writing', 'stance_awe') . '</title>', $svg);
    }

    public function testGraphLegendListsExactlyTheStanceGroups(): void
    {
        $groups = Stances::groups();
        $this->assertSame(
            array_values(array_unique($groups)),
            $groups,
            'groups() must be duplicate-free'
        );
        $this->assertSame(
            ['love', 'bond', 'esteem', 'hostility', 'rivalry', 'fear', 'neutral'],
            $groups
        );
        foreach ($groups as $group) {
            $this->assertNotSame('', Stances::classForGroup($group));
        }
    }

    public function testGraphDeterminismUnaffectedByStance(): void
    {
        $characters = [
            ['id' => 'a', 'slug' => 'jakob', 'title' => 'Jakob', 'level' => 4, 'has_image' => false],
            ['id' => 'b', 'slug' => 'lena', 'title' => 'Lena', 'level' => 8, 'has_image' => false],
        ];
        $relations = [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'spouse', 'stance' => 'love', 'description' => 'devoted'],
            ['from_id' => 'b', 'to_id' => 'a', 'kind' => 'spouse', 'stance' => 'hatred', 'description' => 'cold'],
        ];
        $first = (new RelationshipGraph())->render('p1', $characters, $relations);
        $second = (new RelationshipGraph())->render('p1', $characters, $relations);
        $this->assertSame($first, $second);
        $this->assertStringContainsString('rel-love', $first);
        $this->assertStringContainsString('rel-hostility', $first);
    }

    public function testFormOffersStanceDropdownWithNoStanceDefault(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'kin', 'stance' => 'love'];
        $this->controller->store($this->owner, 'p1');
        $relationId = (string)$this->db->one('SELECT id FROM character_relations')['id'];
        $edit = $this->controller->edit($this->owner, 'p1', $relationId);
        $this->assertSame(200, $edit->status());
        foreach (Stances::allStances() as $stance) {
            $this->assertStringContainsString('value="' . $stance . '"', $edit->body(), "stance option {$stance}");
        }
        // The empty "no stance" default option is present and preselected
        // for the stored stance.
        $this->assertStringContainsString(
            '<option value="love" selected',
            $edit->body()
        );
    }
}
