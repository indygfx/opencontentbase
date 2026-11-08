<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\Auth;
use Core\Avatar;
use Core\CoreMigrations;
use Core\Csrf;
use Core\Database;
use Core\Migrator;
use Core\Translator;
use Core\User;
use Core\View;
use PHPUnit\Framework\TestCase;

/**
 * Prompt 25: the logged-in user's avatar sits in the topbar next to the
 * username/profile link, via the shared avatar_tag() single source (image when
 * uploaded, initials circle otherwise). Decorative: alt=""/aria-hidden, no own
 * click target, hidden below 480px so the wrapped topbar is not squeezed.
 * Guests see no avatar.
 */
final class TopbarAvatarTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $base;
    private string $tmpBase;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $user;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->tmpBase = sys_get_temp_dir() . '/cb_topbar_' . uniqid();
        mkdir($this->tmpBase . '/data', 0775, true);
        Avatar::$basePathOverride = $this->tmpBase;
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $this->db = new Database($this->tmpBase . '/data/contentbase.sqlite');
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->user = User::fromRow($this->db->one(
            'SELECT id, username, role, language, theme, avatar FROM users WHERE id = ?',
            ['u1']
        ));
    }

    protected function tearDown(): void
    {
        Avatar::$basePathOverride = null;
        unset($this->db, $this->auth, $this->user);
        foreach (glob($this->tmpBase . '/data/avatars/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpBase . '/data/avatars');
        @unlink($this->tmpBase . '/data/contentbase.sqlite');
        @rmdir($this->tmpBase . '/data');
        @rmdir($this->tmpBase);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function renderLayout(?User $user): string
    {
        return (new View($this->base))->render('templates/layout.php', [
            'title' => 'T',
            'user' => $user,
            'csrf' => new Csrf($this->db, $this->auth),
            'content' => '<p>x</p>',
        ]);
    }

    private function topbar(string $html): string
    {
        $start = strpos($html, '<header class="topbar">');
        $end = strpos($html, '</header>');
        $this->assertNotFalse($start);
        $this->assertNotFalse($end);
        return substr($html, $start, $end - $start);
    }

    public function testGuestSeesNoAvatarInTopbar(): void
    {
        $topbar = $this->topbar($this->renderLayout(null));
        $this->assertStringNotContainsString('avatar', $topbar);
    }

    public function testLoggedInUserWithoutAvatarGetsInitialsCircleBeforeUsernameLink(): void
    {
        $topbar = $this->topbar($this->renderLayout($this->user));
        // decorative initials circle inside the profile link, before the username text
        $this->assertMatchesRegularExpression(
            '/<a href="\/profile" class="topbar-user"><span class="avatar avatar-tone-\d+" aria-hidden="true">A<\/span> alice<\/a>/',
            $topbar
        );
    }

    public function testLoggedInUserWithAvatarGetsDecorativeImgWithCacheBuster(): void
    {
        mkdir($this->tmpBase . '/data/avatars', 0775, true);
        file_put_contents($this->tmpBase . '/data/avatars/u1.png', base64_decode(self::PNG));
        $this->db->run("UPDATE users SET avatar = 'u1.png' WHERE id = 'u1'");
        $user = User::fromRow($this->db->one('SELECT id, username, role, language, theme, avatar FROM users WHERE id = ?', ['u1']));
        $topbar = $this->topbar($this->renderLayout($user));
        $this->assertMatchesRegularExpression(
            '/<img class="avatar avatar-img" src="\/avatars\/u1\?v=\d+" alt="" aria-hidden="true" loading="lazy"> alice<\/a>/',
            $topbar,
            'uploaded avatar renders as decorative img (alt empty) before the username'
        );
    }

    public function testAvatarIsHiddenBelow480pxAndTopbarWrapRulesIntact(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $phone = substr($css, (int)strpos($css, '@media (max-width: 480px)'));
        $this->assertStringContainsString('.topbar-user .avatar { display: none; }', $phone, 'avatar hidden on phones (wrapped topbar keeps 44px targets)');
        // F1 discipline: topbar still wraps/stacks, nav links keep 44px
        $this->assertStringContainsString('.topbar { flex-direction: column; align-items: flex-start; }', $phone);
        $this->assertMatchesRegularExpression('/\.topbar nav a \{[^}]*min-height: 44px;/', $css);
        $this->assertStringContainsString('.topbar-user { display: inline-flex; align-items: center; gap: 0.4rem; }', $css);
    }
}
