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

    public function testOwnProjectWithManyMembersRendersCappedLinkedStackCounterAndTooltip(): void
    {
        $body = $this->renderOverview($this->owner);
        // one stack (p1) rendered as a LINK to the setup page, capped at 3 circles + one +N counter
        $this->assertSame(1, substr_count($body, '<a class="avatar-stack share-stack"'));
        $this->assertStringContainsString('<a class="avatar-stack share-stack" href="/writing/p1/setup"', $body);
        $this->assertSame(1, substr_count($body, 'avatar-more'));
        $this->assertStringContainsString('>+2</span>', $body);
        // 3 stack avatars + 1 shared-by avatar (p3) = 4 tone circles
        $this->assertSame(4, substr_count($body, 'avatar avatar-tone-'));
        // tooltip carries all five names, aria-label the translated manage-sharing phrase + count
        $this->assertStringContainsString('title="m1, m2, m3, m4, m5"', $body);
        $this->assertStringContainsString('aria-label="Shared with m1, m2, m3, m4, m5 (5) - manage sharing"', $body);
        $this->assertStringContainsString('title="+2 more"', $body);
    }

    public function testSharedProjectRendersSharedByOwnerWithoutMemberStack(): void
    {
        $body = $this->renderOverview($this->owner);
        // p3 is shared with o1: exactly one "shared by" hint naming the owner m1
        $this->assertSame(1, substr_count($body, 'class="shared-by"'));
        $this->assertStringContainsString('Shared by m1', $body);
        // the setup page is owner-only (verified guard below), so the hint is NOT a link
        $this->assertStringContainsString('<span class="shared-by">', $body);
        $this->assertStringNotContainsString('<a class="shared-by"', $body);
        $pos = strpos($body, 'class="shared-by"');
        $this->assertSame('<span', substr($body, $pos - 5, 5), 'shared-by hint must be a plain span, not a link');
        $tdEnd = strpos($body, '</td>', $pos);
        $this->assertStringNotContainsString('<a', substr($body, $pos, (int)$tdEnd - $pos));
        // p3's members do NOT render as a stack for the member view (still only p1's stack link)
        $this->assertSame(1, substr_count($body, '<a class="avatar-stack share-stack"'));
        // as the owner, m1 sees p3 as own project with a 2-member stack link (no counter)
        $bodyAsOwner = $this->renderOverview($this->user('m1'));
        $this->assertSame(1, substr_count($bodyAsOwner, '<a class="avatar-stack share-stack"'));
        $this->assertStringContainsString('href="/writing/p3/setup"', $bodyAsOwner);
        $this->assertSame(0, substr_count($bodyAsOwner, 'avatar-more'));
        $this->assertStringContainsString('aria-label="Shared with m2, o1 (2) - manage sharing"', $bodyAsOwner);
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
        $this->assertStringNotContainsString('share-stack', $row);
    }

    public function testSetupPageShowsFullSharedAccountsListWithAvatars(): void
    {
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $response = $controller->setup($this->owner, 'p1');
        $this->assertSame(200, $response->status());
        $body = $response->body();
        // the Sharing block resolves the +N counter on touch: ALL 5 members listed with avatars
        $this->assertStringContainsString('>Sharing<', $body);
        $this->assertSame(5, substr_count($body, 'avatar avatar-tone-'));
        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $m) {
            $this->assertStringContainsString('<td>' . $m . '</td>', $body);
        }
        // management stays inline on the setup page (revoke per member, share form above)
        $this->assertSame(5, substr_count($body, '/unshare'));
        $this->assertStringContainsString('/share"', $body);
    }

    public function testSetupPageGuardIsOwnerOnly(): void
    {
        $controller = new WritingController(
            $this->db,
            $this->view,
            $this->csrf,
            new ProjectAccess($this->db),
            new ContentRenderer($this->db, new ModuleRegistry())
        );
        $this->assertSame(200, $controller->setup($this->owner, 'p1')->status());
        $this->assertSame(403, $controller->setup($this->user('m2'), 'p1')->status(), 'member must not open the setup page');
        $this->assertSame(404, $controller->setup($this->user('s1'), 'p1')->status(), 'stranger gets 404');
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
        $this->assertStringContainsString('.avatar-stack .avatar { margin-left: -4px; }', $baseSection);
        $this->assertStringContainsString('a.avatar-stack { text-decoration: none;', $baseSection);
        $this->assertStringContainsString('.avatar-more { background: var(--muted); }', $baseSection);
        $this->assertStringContainsString('.shared-by {', $baseSection);
    }

    public function testSharingKeysExistInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $file = $this->base . '/lang/' . $code . '/writing.php';
            $source = (string)file_get_contents($file);
            foreach (['shared_by', 'shared_with_manage', 'more_members', 'sharing_section'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $source, $file);
            }
        }
    }
}
