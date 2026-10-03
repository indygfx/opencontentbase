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
use Writing\RelationshipController;
use Writing\WritingModule;

final class RelationshipAccessTest extends TestCase
{
    private Database $db;
    private RelationshipController $controller;
    private User $owner;
    private User $member;
    private User $stranger;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
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
        $this->controller = new RelationshipController($this->db, new \Core\View($base), new Csrf($this->db, $auth), new \Writing\ProjectAccess($this->db));

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

        foreach ([['c1', 'hero', 'Hero'], ['c2', 'villain', 'Villain']] as [$id, $slug, $title]) {
            $this->db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'o1')",
                [$id, $slug]
            );
            $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES (?, 'p1', ?)", [$id, $slug]);
            $this->db->run(
                'INSERT INTO content_revisions (id, object_id, title, body, author_id) VALUES (?, ?, ?, ?, ?)',
                [Auth::uuid4(), $id, $title, '', 'o1']
            );
        }
        $_POST = [];
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }

    public function testMemberCanCreateRelation(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'rival', 'description' => 'old feud'];
        $response = $this->controller->store($this->member, 'p1');
        $this->assertSame(302, $response->status());
        $row = $this->db->one("SELECT * FROM character_relations WHERE project_id = 'p1'");
        $this->assertSame('rival', (string)$row['kind']);
        $this->assertSame('old feud', (string)$row['description']);
    }

    public function testStrangerCannotCreateRelation(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'friend'];
        $response = $this->controller->store($this->stranger, 'p1');
        $this->assertSame(404, $response->status());
        $this->assertNull($this->db->one("SELECT id FROM character_relations"));
    }

    public function testSelfRelationRejected(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c1', 'kind' => 'friend'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $this->assertNull($this->db->one("SELECT id FROM character_relations"));
    }

    public function testForeignCharacterRejected(): void
    {
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p2', 'o1', 'Other')");
        $this->db->run(
            "INSERT INTO content_objects (id, type, slug, owner_id) VALUES ('c9', 'character', 'other-hero', 'o1')"
        );
        $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES ('c9', 'p2', 'other-hero')");
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c9', 'kind' => 'friend'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
    }

    public function testDuplicateRelationRejected(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'friend'];
        $this->controller->store($this->owner, 'p1');
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $count = (int)$this->db->one("SELECT COUNT(*) AS c FROM character_relations")['c'];
        $this->assertSame(1, $count);
    }

    public function testSamePairSameDirectionRejectedRegardlessOfKind(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'friend'];
        $this->controller->store($this->owner, 'p1');
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'rival'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(422, $response->status());
        $count = (int)$this->db->one("SELECT COUNT(*) AS c FROM character_relations")['c'];
        $this->assertSame(1, $count);
    }

    public function testReversedDirectionIsAllowed(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'lover'];
        $this->controller->store($this->owner, 'p1');
        $_POST = ['from_character_id' => 'c2', 'to_character_id' => 'c1', 'kind' => 'enemy'];
        $response = $this->controller->store($this->owner, 'p1');
        $this->assertSame(302, $response->status());
        $count = (int)$this->db->one("SELECT COUNT(*) AS c FROM character_relations")['c'];
        $this->assertSame(2, $count);
    }

    public function testEditRelationPageAndDirectionalDuplicateOnUpdate(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'friend'];
        $this->controller->store($this->owner, 'p1');
        $relationId = (string)$this->db->one("SELECT id FROM character_relations")['id'];
        $response = $this->controller->edit($this->owner, 'p1', $relationId);
        $this->assertSame(200, $response->status());
        $this->assertStringContainsString('Edit relationship', $response->body());
        $_POST = ['from_character_id' => 'c2', 'to_character_id' => 'c1', 'kind' => 'enemy', 'description' => 'hate'];
        $response = $this->controller->update($this->owner, 'p1', $relationId);
        $this->assertSame(302, $response->status());
        $row = $this->db->one("SELECT * FROM character_relations WHERE id = ?", [$relationId]);
        $this->assertSame('c2', (string)$row['from_character_id']);
        $this->assertSame('enemy', (string)$row['kind']);
    }

    public function testMemberCanDeleteRelation(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'friend'];
        $this->controller->store($this->owner, 'p1');
        $relationId = (string)$this->db->one("SELECT id FROM character_relations")['id'];
        $_POST = ['relation_id' => $relationId];
        $response = $this->controller->destroy($this->member, 'p1');
        $this->assertSame(302, $response->status());
        $this->assertNull($this->db->one("SELECT id FROM character_relations"));
    }

    public function testDeletingCharacterRemovesRelations(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'friend'];
        $this->controller->store($this->owner, 'p1');
        $this->db->run('DELETE FROM characters WHERE id = ?', ['c1']);
        $this->db->run('DELETE FROM content_objects WHERE id = ?', ['c1']);
        $count = (int)$this->db->one("SELECT COUNT(*) AS c FROM character_relations")['c'];
        $this->assertSame(0, $count);
    }

    public function testListShowsTitles(): void
    {
        $_POST = ['from_character_id' => 'c1', 'to_character_id' => 'c2', 'kind' => 'lover', 'description' => 'secret'];
        $this->controller->store($this->owner, 'p1');
        $list = $this->controller->list('p1');
        $this->assertCount(1, $list);
        $this->assertSame('Hero', (string)$list[0]['from_title']);
        $this->assertSame('Villain', (string)$list[0]['to_title']);
        $this->assertSame('secret', (string)$list[0]['description']);
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->controller, $this->owner, $this->member, $this->stranger);
        $_POST = [];
    }
}
