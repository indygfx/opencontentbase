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

/**
 * Per-user theme selection (prompt 22): dropdown on /profile, persisted via
 * POST /profile/theme, strictly validated slugs, layout loads the active
 * theme after editor.css and the user-override dir last.
 */
final class ThemeSelectionTest extends TestCase
{
    private string $base;
    private bool $translatorWasInitialized = false;
    private Database $db;
    private Auth $auth;
    private User $user;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->translatorWasInitialized = Translator::instance() !== null;
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
        $path = sys_get_temp_dir() . '/cb_theme_sel_' . uniqid() . '.sqlite';
        $this->db = new Database($path);
        (new Migrator($this->db))->migrate('core', CoreMigrations::migrations());
        $this->auth = new Auth($this->db);
        $this->db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $this->user = User::fromRow($this->db->one(
            'SELECT id, username, role, language, theme FROM users WHERE id = ?',
            ['u1']
        ));
        $_POST = [];
    }

    protected function tearDown(): void
    {
        $_POST = [];
        unset($this->db, $this->auth, $this->user);
        if (!$this->translatorWasInitialized) {
            $prop = new \ReflectionProperty(Translator::class, 'instance');
            $prop->setValue(null, null);
        }
    }

    private function controller(?\Core\ThemeRegistry $themes = null): AuthController
    {
        return new AuthController($this->auth, new View($this->base), new Csrf($this->db, $this->auth), Translator::instance(), null, $themes);
    }

    public function testSetThemePersistsAndValidates(): void
    {
        $this->assertTrue($this->auth->setTheme($this->user, 'default'));
        $this->assertSame('default', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);
        $this->assertTrue($this->auth->setTheme($this->user, 'nerdline'));
        $this->assertSame('nerdline', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);
        foreach (['../etc', 'Foo', '', 'nerdline/../x'] as $bad) {
            $this->assertFalse($this->auth->setTheme($this->user, $bad), 'must reject: ' . $bad);
        }
        $this->assertSame('nerdline', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);
    }

    public function testProfileRendersThemeDropdownWithNerdlineAndNoDefaultEntry(): void
    {
        $body = $this->controller()->showProfile($this->user)->body();
        $this->assertStringContainsString('id="theme"', $body);
        $this->assertStringContainsString('action="/profile/theme"', $body);
        $this->assertStringContainsString('value="nerdline"', $body);
        $this->assertStringContainsString('Nerdline', $body);
        $this->assertStringContainsString('nerdline media solutions', $body);
        // the 'only master.css' pseudo-entry must be gone - real themes only
        $this->assertStringNotContainsString('value="default"', $body);
    }

    public function testProfileShowsNoThemesMessageInsteadOfDropdownWhenEmpty(): void
    {
        $emptyBase = sys_get_temp_dir() . '/cb_empty_themes_' . uniqid();
        mkdir($emptyBase . '/public/assets/themes', 0775, true);
        $emptyRegistry = new \Core\ThemeRegistry($emptyBase);
        $this->assertSame([], $emptyRegistry->scan());
        $body = $this->controller($emptyRegistry)->showProfile($this->user)->body();
        $this->assertStringNotContainsString('id="theme"', $body, 'no dropdown without themes');
        $this->assertStringContainsString('No themes installed', $body, 'muted info message instead');
    }

    public function testChangeThemePostPersistsAndRendersNewThemeImmediately(): void
    {
        $_POST = ['theme' => 'nerdline'];
        $response = $this->controller()->changeTheme($this->user);
        $this->assertSame(200, $response->status());
        $this->assertSame('nerdline', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);
        // the POST response itself must already render the new theme (user refreshed)
        $this->assertStringContainsString('/assets/themes/nerdline/css/theme.css', $response->body());

        $_POST = ['theme' => '../evil'];
        $response = $this->controller()->changeTheme($this->user);
        $this->assertSame(422, $response->status());
        $this->assertSame('nerdline', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);
        $this->assertStringContainsString('The selected theme is not available.', $response->body());
    }

    public function testLayoutLoadsThemeAfterEditorCssAndOverridesLast(): void
    {
        $view = new View($this->base);
        // default user (master.css only): no theme link at all
        $htmlDefault = $view->render('templates/layout.php', [
            'title' => 'T',
            'user' => $this->user,
            'csrf' => new Csrf($this->db, $this->auth),
            'content' => '<p>x</p>',
        ]);
        $this->assertStringNotContainsString('/assets/themes/', $htmlDefault);
        $this->assertStringContainsString('/assets/css/master.css', $htmlDefault);
        $this->assertStringNotContainsString('/assets/overrides/', $htmlDefault, 'no override dir present in the repo');

        // user with theme=nerdline: theme link AFTER editor.css, with cache-buster
        $this->db->run("UPDATE users SET theme = 'nerdline' WHERE id = 'u1'");
        $userNerd = User::fromRow($this->db->one('SELECT id, username, role, language, theme FROM users WHERE id = ?', ['u1']));
        $html = $view->render('templates/layout.php', [
            'title' => 'T',
            'user' => $userNerd,
            'csrf' => new Csrf($this->db, $this->auth),
            'content' => '<p>x</p>',
        ]);
        $master = strpos($html, '/assets/css/master.css');
        $editor = strpos($html, '/assets/css/editor.css');
        $theme = strpos($html, '/assets/themes/nerdline/css/theme.css');
        $this->assertNotFalse($theme);
        $this->assertGreaterThan($master, $editor);
        $this->assertGreaterThan($editor, $theme, 'theme must load AFTER editor.css');
        $this->assertMatchesRegularExpression('/themes\/nerdline\/css\/theme\.css\?v=\d+/', $html, 'cache-buster required (SW caches /assets/ cache-first)');
        $this->assertStringContainsString('<meta name="theme-color" content="#16a34a">', $html);
    }

    public function testThemeKeysExistInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/core.php');
            foreach (['theme_label', 'profile_theme_none', 'profile_theme', 'profile_theme_submit', 'profile_theme_invalid', 'profile_theme_changed'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . '/core.php missing ' . $key);
            }
        }
    }
}
