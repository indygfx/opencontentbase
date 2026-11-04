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
use Core\Translator;
use Core\User;
use Core\View;
use PHPUnit\Framework\TestCase;
use Writing\Avatar;
use Writing\ProjectAccess;
use Writing\WritingController;
use Writing\WritingModule;

/**
 * Writing overview (/writing) shows the sharing state on each project row:
 * own projects get a capped avatar stack (+N counter, full names in the
 * tooltip), shared projects a "shared by" hint with the owner's avatar.
 * The members are fetched with ONE grouped query (membersForProjects).
 */
final class WritingSharingOverviewTest extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private Csrf $csrf;
    private View $view;
    private User $owner;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_sharing_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->csrf = new Csrf($this->db, $this->auth);
        $this->view = new View($this->base);
        $module = new WritingModule($this->db, $this->view, $this->csrf, new ContentRenderer($this->db, new ModuleRegistry()));
        (new Migrator($this->db))->migrate('writing', $module->migrations());
        foreach (['o1' => 'admin', 'm1' => 'user', 'm2' => 'user', 'm3' => 'user', 'm4' => 'user', 'm5' => 'user', 's1' => 'user'] as $id => $role) {
            $this->db->run(
                'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
                [$id, $id, password_hash('x1234567', PASSWORD_BCRYPT), $role]
            );
        }
        $this->owner = User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = 'o1'"));
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p1', 'o1', 'Own shared story')");
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p2', 'o1', 'Own private story')");
        $this->db->run("INSERT INTO projects (id, owner_id, title) VALUES ('p3', 'm1', 'Shared story')");
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $m) {
            $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p1', ?, 'member')", [$m]);
        }
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p3', 'o1', 'member')");
        $this->db->run("INSERT INTO project_members (project_id, user_id, role) VALUES ('p3', 'm2', 'member')");
        $_POST = [];
    }

    protected function tearDown(): void
    {
        unset($this->db, $this->auth, $this->csrf, $this->view, $this->owner);
        $_POST = [];
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function renderOverview(User $user): string
    {
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        return $controller->index($user)->body();
    }

    private function user(string $id): User
    {
        return User::fromRow($this->db->one("SELECT id, username, role FROM users WHERE id = ?", [$id]));
    }

    public function testOwnProjectWithManyMembersRendersCappedStackCounterAndTooltip(): void
    {
        $body = $this->renderOverview($this->owner);
        // one stack (p1), capped at 3 avatar circles + one +N counter circle
        $this->assertSame(1, substr_count($body, 'class="avatar-stack"'));
        $this->assertSame(1, substr_count($body, 'avatar-more'));
        $this->assertStringContainsString('>+2</span>', $body);
        // 3 stack avatars + 1 shared-by avatar (p3) = 4 tone circles
        $this->assertSame(4, substr_count($body, 'avatar avatar-tone-'));
        // tooltip carries all five names, aria-label the translated text + count
        $this->assertStringContainsString('title="m1, m2, m3, m4, m5"', $body);
        $this->assertStringContainsString('aria-label="Shared with m1, m2, m3, m4, m5 (5)"', $body);
        $this->assertStringContainsString('title="+2 more"', $body);
    }

    public function testSharedProjectRendersSharedByOwnerWithoutMemberStack(): void
    {
        $body = $this->renderOverview($this->owner);
        // p3 is shared with o1: exactly one "shared by" hint naming the owner m1
        $this->assertSame(1, substr_count($body, 'class="shared-by"'));
        $this->assertStringContainsString('Shared by m1', $body);
        // p3's members do NOT render as a stack for the member view (still only p1's stack)
        $this->assertSame(1, substr_count($body, 'class="avatar-stack"'));
        // as the owner, m1 sees p3 as own project with a 2-member stack (no counter)
        $bodyAsOwner = $this->renderOverview($this->user('m1'));
        $this->assertSame(1, substr_count($bodyAsOwner, 'class="avatar-stack"'));
        $this->assertSame(0, substr_count($bodyAsOwner, 'avatar-more'));
        $this->assertStringContainsString('aria-label="Shared with m2, o1 (2)"', $bodyAsOwner);
        $this->assertStringContainsString('Shared by o1', $bodyAsOwner);
    }

    public function testOwnProjectWithoutMembersRendersNoSharingBlock(): void
    {
        $body = $this->renderOverview($this->owner);
        $pos = strpos($body, 'Own private story');
        $this->assertNotFalse($pos);
        $row = substr($body, $pos, (int)strpos($body, '</tr>', $pos) - $pos);
        $this->assertStringNotContainsString('avatar', $row);
        $this->assertStringNotContainsString('shared-by', $row);
    }

    public function testMemberFetchIsOneGroupedQuery(): void
    {
        $access = (string)file_get_contents($this->base . '/modules/Writing/src/ProjectAccess.php');
        $this->assertStringContainsString('function membersForProjects', $access);
        $this->assertMatchesRegularExpression('/WHERE m\.project_id IN \(\$placeholders\)/', $access);
        $controller = (string)file_get_contents($this->base . '/modules/Writing/src/WritingController.php');
        // exactly one membersForProjects() call for the whole overview (no per-row members() loop)
        $this->assertSame(1, substr_count($controller, 'membersForProjects('));
    }

    public function testAvatarHelpersAreDeterministic(): void
    {
        $this->assertSame('JD', Avatar::initials('john doe'));
        $this->assertSame('R', Avatar::initials('rootuser'));
        $tone = Avatar::tone('m1');
        $this->assertSame($tone, Avatar::tone('m1'));
        $this->assertGreaterThanOrEqual(1, $tone);
        $this->assertLessThanOrEqual(Avatar::TONES, $tone);
    }

    public function testMasterCssDefinesAvatarPaletteAndStackRulesInBase(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $baseSection = substr($css, 0, (int)strpos($css, '@media'));
        foreach (range(1, 6) as $i) {
            $this->assertStringContainsString('--avatar-' . $i . ':', $baseSection);
            $this->assertStringContainsString('.avatar-tone-' . $i . ' { background: var(--avatar-' . $i . '); }', $baseSection);
        }
        $this->assertStringContainsString('.avatar-stack { display: inline-flex;', $baseSection);
        $this->assertStringContainsString('.avatar { width: 28px; height: 28px;', $baseSection);
        $this->assertStringContainsString('border: 2px solid var(--surface);', $baseSection);
        $this->assertStringContainsString('margin-left: -4px;', $baseSection);
        $this->assertStringContainsString('.avatar-more { background: var(--muted); }', $baseSection);
        $this->assertStringContainsString('.shared-by {', $baseSection);
    }

    public function testSharingKeysExistInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $file = $this->base . '/lang/' . $code . '/writing.php';
            $source = (string)file_get_contents($file);
            foreach (['shared_by', 'shared_with_names', 'more_members'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $source, $file);
            }
        }
    }
}
