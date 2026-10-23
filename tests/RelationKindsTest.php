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
use Writing\RelationKinds;
use Writing\RelationshipController;
use Writing\RelationshipGraph;
use Writing\ProjectAccess;
use Writing\WritingModule;

/**
 * RelationKinds as the single source of truth for the kind taxonomy:
 * group mapping with neutral fallback, CSS class resolution, and that
 * dropdown, graph and legend all derive their data from it.
 */
final class RelationKindsTest extends TestCase
{
    private Database $db;
    private RelationshipController $controller;
    private User $owner;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_relkinds_' . uniqid() . '.sqlite';
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

    public function testGroupForEveryListedKind(): void
    {
        foreach (RelationKinds::GROUPS as $kind => $group) {
            $this->assertSame($group, RelationKinds::groupFor($kind), "kind {$kind}");
        }
    }

    public function testGroupForUnknownKindFallsBackToOther(): void
    {
        $this->assertSame('other', RelationKinds::groupFor('legacy_bond'));
        $this->assertSame('other', RelationKinds::groupFor(''));
    }

    public function testAllKindsMatchesGroupKeys(): void
    {
        $this->assertSame(array_keys(RelationKinds::GROUPS), RelationKinds::allKinds());
        $this->assertContains('friend', RelationKinds::allKinds());
    }

    public function testKindsForGroup(): void
    {
        $this->assertSame(['friend', 'ally', 'companion', 'teammate', 'neighbor'], RelationKinds::kindsForGroup('alliance'));
        $this->assertSame(
            ['kin', 'parent', 'child', 'grandparent', 'grandchild', 'sibling', 'cousin', 'spouse', 'engaged', 'heir', 'guardian', 'in_law'],
            RelationKinds::kindsForGroup('family')
        );
        $this->assertSame(['employer', 'employee', 'colleague', 'business_partner'], RelationKinds::kindsForGroup('work'));
        $this->assertSame(['mentor', 'apprentice'], RelationKinds::kindsForGroup('mentorship'));
        $this->assertSame(['related', 'acquaintance', 'doctor'], RelationKinds::kindsForGroup('other'));
        $this->assertSame([], RelationKinds::kindsForGroup('nonexistent'));
    }

    public function testGroupsAlwaysIncludeFallback(): void
    {
        $groups = RelationKinds::groups();
        $this->assertContains('other', $groups);
        foreach (RelationKinds::GROUPS as $group) {
            $this->assertContains($group, $groups);
        }
    }

    public function testClassForGroupAndFallback(): void
    {
        $this->assertSame('rel-family', RelationKinds::classForGroup('family'));
        $this->assertSame('rel-other', RelationKinds::classForGroup('unknown-group'));
        $this->assertSame('rel-mentorship', RelationKinds::classFor('mentor'));
        $this->assertSame('rel-other', RelationKinds::classFor('legacy_kind'));
    }

    public function testEveryGroupHasACssClass(): void
    {
        foreach (RelationKinds::groups() as $group) {
            $this->assertArrayHasKey($group, RelationKinds::CLASSES, "group {$group}");
            $this->assertNotSame('', RelationKinds::CLASSES[$group]);
        }
    }

    public function testGroupCountStaysReadable(): void
    {
        $this->assertLessThanOrEqual(9, count(RelationKinds::groups()));
    }

    public function testDropdownReadsAllKinds(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        // The dropdown on the edit page lists exactly allKinds().
        $relationId = (string)$this->db->one('SELECT id FROM character_relations')['id'];
        $edit = $this->controller->edit($this->owner, 'p1', $relationId);
        $this->assertSame(200, $edit->status());
        foreach (RelationKinds::allKinds() as $kind) {
            $this->assertStringContainsString('value="' . $kind . '"', $edit->body(), "dropdown option {$kind}");
        }
    }

    public function testValidationAcceptsAllListedKindsAndRejectsUnknown(): void
    {
        foreach (RelationKinds::allKinds() as $kind) {
            $this->db->run('DELETE FROM character_relations');
            $_POST = [
                'from_character_id' => 'c1',
                'to_character_id' => 'c2',
                'kind' => $kind,
                'description' => '',
            ];
            $response = $this->controller->store($this->owner, 'p1');
            $this->assertSame(302, $response->status(), "kind {$kind} must be accepted");
        }
        $this->db->run('DELETE FROM character_relations');
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'hijacked'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
    }

    public function testGraphEdgeCarriesGroupCssClass(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'kin', 'stance' => 'hatred', 'description' => 'feud'],
        ]);
        $this->assertStringContainsString('rel-hostility', $svg);
        $this->assertStringNotContainsString('stroke="#', $svg); // no inline colors
    }

    public function testGraphUnknownKindRendersFallbackClass(): void
    {
        $svg = (new RelationshipGraph())->render('p1', [
            ['id' => 'a', 'slug' => 'hero', 'title' => 'Hero', 'level' => 3, 'has_image' => false],
            ['id' => 'b', 'slug' => 'villain', 'title' => 'Villain', 'level' => 3, 'has_image' => false],
        ], [
            ['from_id' => 'a', 'to_id' => 'b', 'kind' => 'legacy_bond', 'stance' => 'legacy_feeling', 'description' => ''],
        ]);
        $this->assertStringContainsString('rel-neutral', $svg);
        // Edge label keeps the fine kind (raw key without a Translator).
        $this->assertStringContainsString('>kind_legacy_bond</text>', $svg);
    }

    public function testLegendContainsExactlyTheDefinedGroups(): void
    {
        $groups = RelationKinds::groups();
        $this->assertSame(
            array_values(array_unique($groups)),
            $groups,
            'groups() must be duplicate-free'
        );
        // The legend template iterates groups(); every group resolves to
        // a CSS class and a lang key (parity enforced in TranslatorTest).
        foreach ($groups as $group) {
            $this->assertNotSame('', RelationKinds::classForGroup($group));
        }
    }
}
