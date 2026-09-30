<?php
declare(strict_types=1);

namespace Tests\Writing;

use Core\Auth;
use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use Core\User;
use PHPUnit\Framework\TestCase;
use Writing\ProjectAccess;
use Writing\WritingModule;

final class ProjectAccessTest extends TestCase
{
    private Database $db;
    private ProjectAccess $access;
    private User $owner;
    private User $other;
    private User $member;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        (new Migrator($this->db))->migrate('writing', (new \Writing\WritingModule($this->db, new \Core\View(dirname(__DIR__)), new \Core\Csrf($this->db, new Auth($this->db))))->migrations());
        $this->access = new ProjectAccess($this->db);

        foreach ([['o1', 'owner'], ['u1', 'other'], ['m1', 'member']] as [$id, $name]) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $name, password_hash('x1234567', PASSWORD_BCRYPT), 'user']
            );
        }
        $this->owner = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $this->other = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'u1'"));
        $this->member = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'm1'"));

        $this->db->run(
            "INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')"
        );
        $this->db->run(
            "INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', 'm1', 'member')"
        );
    }

    public function testOwnerSeesOwnProject(): void
    {
        $this->assertTrue($this->access->canAccess($this->owner, 'p1'));
        $this->assertTrue($this->access->isOwner($this->owner, 'p1'));
        $this->assertTrue($this->access->canEdit($this->owner, 'p1'));
    }

    public function testMemberSeesSharedProject(): void
    {
        $this->assertTrue($this->access->canAccess($this->member, 'p1'));
        $this->assertTrue($this->access->canEdit($this->member, 'p1'));
        $this->assertFalse($this->access->isOwner($this->member, 'p1'));
    }

    public function testOutsiderSeesNothing(): void
    {
        $this->assertFalse($this->access->canAccess($this->other, 'p1'));
        $this->assertFalse($this->access->canEdit($this->other, 'p1'));
        $this->assertFalse($this->access->isOwner($this->other, 'p1'));
    }

    public function testUnknownProjectIsInvisible(): void
    {
        $this->assertFalse($this->access->canAccess($this->owner, 'nope'));
    }

    public function testVisibleProjectsOwnPlusShared(): void
    {
        $mine = $this->access->visibleProjects($this->owner);
        $this->assertSame(['p1'], array_column($mine, 'id'));

        $theirs = $this->access->visibleProjects($this->member);
        $this->assertSame(['p1'], array_column($theirs, 'id'));

        $none = $this->access->visibleProjects($this->other);
        $this->assertSame([], array_column($none, 'id'));
    }

    public function testSharedFlagDistinguishesOwnerAndMember(): void
    {
        $asMember = $this->access->visibleProjects($this->member);
        $this->assertSame(1, (int)$asMember[0]['shared']);
        $asOwner = $this->access->visibleProjects($this->owner);
        $this->assertSame(0, (int)$asOwner[0]['shared']);
    }

    public function testMembersListsSharedUsers(): void
    {
        $members = $this->access->members('p1');
        $this->assertSame(['member'], array_column($members, 'username'));
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->access, $this->owner, $this->other, $this->member);
    }
}
