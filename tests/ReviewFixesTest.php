<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\AuthController;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\Translator;
use Core\User;
use Core\View;
use PHPUnit\Framework\TestCase;
use Writing\ProjectAccess;
use Writing\RelationshipController;
use Writing\WritingController;
use Writing\WritingModule;

final class ReviewFixesTest extends TestCase
{
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;
    private View $view;

    protected function setUp(): void
    {
        $base = dirname(__DIR__);
        $path = sys_get_temp_dir() . '/cb_test_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->csrf = new Csrf($this->db, $this->auth);
        $this->view = new View($base);
        (new Migrator($this->db))->migrate(
            'writing',
            (new WritingModule(
                $this->db,
                $this->view,
                $this->csrf,
                new \Core\ContentRenderer($this->db, new \Core\ModuleRegistry())
            ))->migrations()
        );
        Translator::init($base . '/lang');
        Translator::instance()?->setLang('en');
        $_POST = [];
        $_FILES = [];
        $_COOKIE = [];
    }

    public function testLoginAndProfilePagesContainTranslatedTitle(): void
    {
        $controller = new AuthController($this->auth, $this->view, $this->csrf, Translator::instance());
        $this->assertStringContainsString('<title>' . t('core', 'login_title') . '</title>', $controller->showLogin()->body());
        $this->assertStringContainsString('<title>' . t('core', 'login_title') . '</title>', $controller->login()->body());
        $user = $this->insertUser('u1', 'alice', 'user');
        $profile = $controller->showProfile($user);
        $this->assertStringContainsString('<title>' . t('core', 'profile_title') . '</title>', $profile->body());
        $password = $controller->changePassword($user);
        $this->assertStringContainsString('<title>' . t('core', 'profile_title') . '</title>', $password->body());
    }

    public function testWritingIndexContainsTranslatedTitle(): void
    {
        $this->insertUser('o1', 'owner', 'user');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $user = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new \Core\ContentRenderer($this->db, new \Core\ModuleRegistry())
        );
        $response = $controller->index($user);
        $this->assertStringContainsString('<title>' . t('writing', 'stories_title') . '</title>', $response->body());
    }

    public function testLayoutUsesActiveLanguageAsHtmlLang(): void
    {
        $controller = new AuthController($this->auth, $this->view, $this->csrf, Translator::instance());
        $this->assertStringContainsString('<html lang="en">', $controller->showLogin()->body());
        Translator::instance()?->setLang('de');
        $this->assertStringContainsString('<html lang="de">', $controller->showLogin()->body());
    }

    public function testRouterAndUserExceptionsAreEnglish(): void
    {
        $router = new \Core\Router();
        try {
            $router->dispatch('GET', '/no-such-route');
            $this->fail('expected RouteNotFoundException');
        } catch (\Core\RouteNotFoundException $e) {
            $this->assertSame('No route for GET /no-such-route', $e->getMessage());
        }
        try {
            new User('u1', 'alice', 'superadmin');
            $this->fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('Unknown role: superadmin', $e->getMessage());
        }
    }

    public function testUserAdminSessionCountIgnoresExpiredSessions(): void
    {
        $this->insertUser('u1', 'alice', 'user');
        $this->db->run(
            "INSERT INTO sessions (id, user_id, token_hash, expires_at) VALUES
             ('s1', 'u1', 'h1', '2000-01-01 00:00:00'),
             ('s2', 'u1', 'h2', '2999-01-01 00:00:00')"
        );
        $admin = $this->insertUser('a1', 'admin', 'admin');
        $controller = new \Core\UserAdminController($this->db, $this->auth, $this->view, $this->csrf);
        $body = $controller->index($admin)->body();
        $this->assertStringContainsString(t('core', 'users_active_sessions'), $body);
        $this->assertMatchesRegularExpression(
            '#<td>1</td>#',
            $body
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<td>2</td>#',
            $body
        );
    }

    public function testUserAdminProtectionMessagesAreTranslated(): void
    {
        // No username is protected anymore; the self-protection rules
        // (own role, self delete) are the remaining invariants.
        $admin = $this->insertUser('root1', 'root', 'admin');
        $this->insertUser('a1', 'someadmin', 'admin');
        $controller = new \Core\UserAdminController($this->db, $this->auth, $this->view, $this->csrf);
        // An admin may demote another admin (no hardcoded username).
        $_POST = ['role' => 'user'];
        $this->assertSame(200, $controller->updateRole($admin, 'a1')->status());
        $this->assertSame('user', (string)$this->db->one("SELECT role FROM users WHERE id = 'a1'")['role']);
        // Self-protection: own role cannot change.
        $_POST = ['role' => 'user'];
        $this->assertStringContainsString(t('core', 'users_error_own_role'), $controller->updateRole($admin, 'root1')->body());
        // Self-protection: cannot delete self.
        $this->assertStringContainsString(t('core', 'users_error_delete_self'), $controller->destroy($admin, 'root1')->body());
        // Deleting another user is allowed (no hardcoded username).
        $this->assertSame(200, $controller->destroy($admin, 'a1')->status());
    }

    public function testRelationPairUniqueRegardlessOfKind(): void
    {
        $this->insertUser('o1', 'owner', 'user');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        foreach ([['c1', 'hero'], ['c2', 'villain']] as [$id, $slug]) {
            $this->db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'o1')",
                [$id, $slug]
            );
            $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES (?, 'p1', ?)", [$id, $slug]);
        }
        $this->db->run(
            "INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind)
             VALUES ('r1', 'p1', 'c1', 'c2', 'rival')"
        );
        $this->expectException(\PDOException::class);
        $this->db->run(
            "INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind)
             VALUES ('r2', 'p1', 'c1', 'c2', 'friend')"
        );
    }

    public function testDestroyRelationRequiresExistingRelation(): void
    {
        $owner = $this->insertUser('o1', 'owner', 'user');
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'My novel')");
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p2', 'o1', 'Other story')");
        foreach ([['c1', 'hero'], ['c2', 'villain']] as [$id, $slug]) {
            $this->db->run(
                "INSERT INTO content_objects (id, type, slug, owner_id) VALUES (?, 'character', ?, 'o1')",
                [$id, $slug]
            );
            $this->db->run("INSERT INTO characters (id, project_id, slug) VALUES (?, 'p1', ?)", [$id, $slug]);
        }
        $this->db->run(
            "INSERT INTO character_relations (id, project_id, from_character_id, to_character_id, kind)
             VALUES ('r1', 'p1', 'c1', 'c2', 'rival')"
        );
        $controller = new RelationshipController($this->db, $this->view, $this->csrf, new ProjectAccess($this->db));

        $_POST = ['relation_id' => 'missing'];
        $this->assertSame(404, $controller->destroy($owner, 'p1')->status());

        $_POST = ['relation_id' => 'r1'];
        $this->assertSame(404, $controller->destroy($owner, 'p2')->status());
        $this->assertNotNull($this->db->one("SELECT id FROM character_relations WHERE id = 'r1'"));

        $_POST = ['relation_id' => 'r1'];
        $this->assertSame(302, $controller->destroy($owner, 'p1')->status());
        $this->assertNull($this->db->one("SELECT id FROM character_relations WHERE id = 'r1'"));
    }


    private function insertUser(string $id, string $username, string $role): User
    {
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            [$id, $username, password_hash('x1234567', PASSWORD_BCRYPT), $role]
        );
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }
}
