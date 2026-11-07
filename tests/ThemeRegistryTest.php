<?php

declare(strict_types=1);

namespace Tests\Core;

use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use Core\ThemeRegistry;
use Core\User;
use PHPUnit\Framework\TestCase;

/**
 * Theme registry (prompt 22): themes are static folders under
 * public/assets/themes/<slug>/ with a theme.json; the registry scans the live
 * folder, validates slugs strictly (no path traversal) and resolves the
 * active per-user theme. Nerdline ships as the tracked global default.
 */
final class ThemeRegistryTest extends TestCase
{
    private string $base;
    private ThemeRegistry $registry;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        $this->registry = new ThemeRegistry($this->base);
    }

    public function testScanFindsNerdlineWithMetadata(): void
    {
        $themes = $this->registry->scan();
        $this->assertNotEmpty($themes);
        $nerdline = null;
        foreach ($themes as $t) {
            if ($t['slug'] === 'nerdline') {
                $nerdline = $t;
            }
        }
        $this->assertNotNull($nerdline, 'nerdline master theme must be installed');
        $this->assertSame('Nerdline', $nerdline['name']);
        $this->assertSame('nerdline media solutions', $nerdline['developer']);
        $this->assertSame('1.0.0', $nerdline['version']);
        $this->assertSame('#16a34a', $nerdline['theme_color']);
        $this->assertSame('#14532d', $nerdline['background_color']);
        $this->assertCount(3, $nerdline['icons']);
        foreach ($nerdline['icons'] as $icon) {
            $this->assertFileExists($this->base . '/public' . $icon['src'], 'theme icon missing: ' . $icon['src']);
        }
    }

    public function testSlugValidationRejectsTraversalAndUnknown(): void
    {
        $this->assertTrue($this->registry->isValidSlug('nerdline'));
        $this->assertFalse($this->registry->isValidSlug('default'), "'default' is the no-theme choice, not a folder");
        foreach (['', 'Foo', 'a b', '../etc', '..', 'nerdline/../x', 'nerdline%00'] as $bad) {
            $this->assertFalse($this->registry->isValidSlug($bad), 'must reject: ' . $bad);
        }
    }

    public function testAssetPathsAreNullForInvalidOrMissing(): void
    {
        $this->assertSame('/assets/themes/nerdline/css/theme.css', $this->registry->cssPath('nerdline'));
        $this->assertSame($this->base . '/public/assets/themes/nerdline/css/theme.css', $this->registry->cssAbs('nerdline'));
        $this->assertNull($this->registry->jsPath('nerdline'), 'theme JS is optional - nerdline ships none');
        $this->assertNull($this->registry->cssPath('default'));
        $this->assertNull($this->registry->cssPath('../etc'));
        $this->assertNull($this->registry->cssPath('nope'));
        $this->assertNull($this->registry->meta('nope'));
    }

    public function testActiveThemeDefaultsToNoThemeMasterCssOnly(): void
    {
        $this->assertSame('default', $this->registry->activeTheme(null), 'default is plain master.css (NO_THEME)');
        $user = new User('u1', 'alice', 'user', null, 'default');
        $this->assertSame('default', $this->registry->activeTheme($user));
        $nerd = new User('u2', 'bob', 'user', null, 'nerdline');
        $this->assertSame('nerdline', $this->registry->activeTheme($nerd));
        $this->assertSame('#16a34a', $this->registry->activeThemeColor(null));
    }

    public function testMigrationDefaultsUsersThemeToMasterCssOnly(): void
    {
        $path = sys_get_temp_dir() . '/cb_theme_' . uniqid() . '.sqlite';
        $db = new Database($path);
        (new Migrator($db))->migrate('core', CoreMigrations::migrations());
        $db->run(
            'INSERT INTO users (id, username, password, role) VALUES (?, ?, ?, ?)',
            ['u1', 'alice', password_hash('x1234567', PASSWORD_BCRYPT), 'user']
        );
        $row = $db->one('SELECT theme FROM users WHERE id = ?', ['u1']);
        $this->assertSame('default', (string)$row['theme'], 'default stays master.css only');
        $user = User::fromRow($db->one('SELECT id, username, role, language, theme FROM users WHERE id = ?', ['u1']));
        $this->assertSame('default', $user->theme());
    }

    public function testNerdlineThemeCssIsVariablesOnly(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/themes/nerdline/css/theme.css');
        $lines = explode("\n", $css);
        $this->assertSame('/* Nerdline theme – nerdline media solutions. Overrides :root variables only. */', $lines[0]);
        $rest = implode("\n", array_slice($lines, 1));
        $this->assertMatchesRegularExpression('/^\s*:root\s*\{[^}]*\}\s*$/s', $rest, 'theme CSS must be a single :root block (variables only)');
        $pos = strpos($css, ':root');
        $end = strpos($css, '}', $pos);
        $outside = substr($css, 0, $pos) . substr($css, (int)$end + 1);
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside), 'no color literals outside :root');
    }

    public function testGitignoreThemePolicyAndNoLegacyThemeCss(): void
    {
        $gitignore = (string)file_get_contents($this->base . '/.gitignore');
        $this->assertStringContainsString('/public/assets/themes/*', $gitignore);
        $this->assertStringContainsString('!/public/assets/themes/nerdline/', $gitignore);
        $this->assertStringContainsString('/public/assets/overrides/', $gitignore);
        $this->assertFileDoesNotExist($this->base . '/public/assets/css/theme.css', 'legacy theme.css must be gone');
        $this->assertFileExists($this->base . '/public/assets/themes/nerdline/css/theme.css');
        $this->assertFileExists($this->base . '/public/assets/themes/nerdline/theme.json');
    }

    public function testOverridePathsAreNullWhenDirAbsent(): void
    {
        $this->assertNull($this->registry->overrideCssPath());
        $this->assertNull($this->registry->overrideJsPath());
    }
}
