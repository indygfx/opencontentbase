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

    private function controller(): AuthController
    {
        return new AuthController($this->auth, new View($this->base), new Csrf($this->db, $this->auth), Translator::instance());
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

    public function testProfileRendersThemeDropdownWithNerdline(): void
    {
        $body = $this->controller()->showProfile($this->user)->body();
        $this->assertStringContainsString('id="theme"', $body);
        $this->assertStringContainsString('action="/profile/theme"', $body);
        $this->assertStringContainsString('value="default"', $body);
        $this->assertStringContainsString('value="nerdline"', $body);
        $this->assertStringContainsString('Nerdline', $body);
        $this->assertStringContainsString('nerdline media solutions', $body);
    }

    public function testChangeThemePostPersistsAndRejectsInvalid(): void
    {
        $_POST = ['theme' => 'default'];
        $response = $this->controller()->changeTheme($this->user);
        $this->assertSame(200, $response->status());
        $this->assertSame('default', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);

        $_POST = ['theme' => '../evil'];
        $response = $this->controller()->changeTheme($this->user);
        $this->assertSame(422, $response->status());
        $this->assertSame('default', (string)$this->db->one('SELECT theme FROM users WHERE id = ?', ['u1'])['theme']);
        $this->assertStringContainsString('The selected theme is not available.', $response->body());
    }

    public function testLayoutLoadsThemeAfterEditorCssAndOverridesLast(): void
    {
        $view = new View($this->base);
        // nerdline user (the default)
        $html = $view->render('templates/layout.php', [
            'title' => 'T',
            'user' => $this->user,
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
        $this->assertStringNotContainsString('/assets/overrides/', $html, 'no override dir present in the repo');

        // 'default' user: no theme link at all
        $this->db->run("UPDATE users SET theme = 'default' WHERE id = 'u1'");
        $userDefault = User::fromRow($this->db->one('SELECT id, username, role, language, theme FROM users WHERE id = ?', ['u1']));
        $htmlDefault = $view->render('templates/layout.php', [
            'title' => 'T',
            'user' => $userDefault,
            'csrf' => new Csrf($this->db, $this->auth),
            'content' => '<p>x</p>',
        ]);
        $this->assertStringNotContainsString('/assets/themes/', $htmlDefault);
        $this->assertStringContainsString('/assets/css/master.css', $htmlDefault);
    }

    public function testThemeKeysExistInAllLanguages(): void
    {
        foreach (['en', 'de', 'es', 'fr'] as $code) {
            $src = (string)file_get_contents($this->base . '/lang/' . $code . '/core.php');
            foreach (['theme_label', 'theme_default', 'profile_theme', 'profile_theme_submit', 'profile_theme_invalid', 'profile_theme_changed'] as $key) {
                $this->assertStringContainsString("'" . $key . "'", $src, $code . '/core.php missing ' . $key);
            }
        }
    }
}
