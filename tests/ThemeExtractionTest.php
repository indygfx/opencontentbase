<?php
declare(strict_types=1);

namespace Tests\Theming;

use Core\Csrf;
use Core\CoreMigrations;
use Core\Database;
use Core\Migrator;
use Core\Translator;
use Core\View;
use PHPUnit\Framework\TestCase;
use Writing\Stances;

/**
 * Theme extraction: the layout loads one external master stylesheet and one
 * static app.js; no template keeps inline <style> or inline <script> blocks;
 * master.css carries a :root block and the full stance-group variable set.
 */
final class ThemeExtractionTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
        Translator::init($this->base . '/lang');
        Translator::instance()?->setLang('en');
    }

    private function renderLayout(): string
    {
        $path = sys_get_temp_dir() . '/cb_theme_' . uniqid() . '.sqlite';
        $db = new Database($path);
        (new Migrator($db))->migrate('core', CoreMigrations::migrations());
        $csrf = new Csrf($db, new \Core\Auth($db));
        return (new View($this->base))->render('templates/layout.php', [
            'title' => 'Test',
            'content' => '<p>body</p>',
            'csrf' => $csrf,
        ]);
    }

    public function testLayoutLoadsMasterCssExactlyOnce(): void
    {
        $html = $this->renderLayout();
        $this->assertSame(1, substr_count($html, 'href="/assets/css/master.css"'));
        $this->assertStringContainsString('<link rel="stylesheet" href="/assets/css/master.css">', $html);
    }

    public function testLayoutLoadsAppJs(): void
    {
        $html = $this->renderLayout();
        $this->assertSame(1, substr_count($html, 'src="/assets/js/app.js"'));
        $this->assertStringContainsString('<script src="/assets/js/app.js" defer></script>', $html);
    }

    public function testLayoutHasNoInlineStyleOrScriptBlocks(): void
    {
        $html = $this->renderLayout();
        $this->assertStringNotContainsString('<style', $html);
        $this->assertSame(0, preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $html));
    }

    public function testLayoutLoadsGlobalDefaultThemeAfterEditorCss(): void
    {
        // the legacy single-file slot is gone; themes live in themes/<slug>/
        $this->assertFileDoesNotExist($this->base . '/public/assets/css/theme.css');
        $this->assertFileExists($this->base . '/public/assets/themes/nerdline/css/theme.css');
        $this->assertFileExists($this->base . '/public/assets/themes/nerdline/theme.json');
        $html = $this->renderLayout(); // no user -> global default 'nerdline'
        $editorPos = strpos($html, '/assets/css/editor.css');
        $themePos = strpos($html, '/assets/themes/nerdline/css/theme.css');
        $this->assertNotFalse($editorPos);
        $this->assertNotFalse($themePos, 'global default theme must be linked');
        $this->assertGreaterThan($editorPos, $themePos, 'active theme must load AFTER editor.css');
        $this->assertMatchesRegularExpression('/themes\/nerdline\/css\/theme\.css\?v=\d+/', $html, 'cache-buster on theme link');
        $this->assertStringContainsString('<meta name="theme-color" content="#16a34a">', $html);
    }

    public function testNoTemplateContainsInlineStyleOrScript(): void
    {
        $files = array_merge(
            glob($this->base . '/templates/*.php') ?: [],
            glob($this->base . '/modules/*/templates/*.php') ?: []
        );
        $this->assertNotEmpty($files);
        foreach ($files as $file) {
            $source = (string)file_get_contents($file);
            $this->assertStringNotContainsString('<style', $source, $file);
            $this->assertSame(0, (int)preg_match('/<script(?![^>]*\bsrc=)[^>]*>/', $source), $file);
        }
    }

    public function testMasterCssExistsWithRootBlock(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString(':root', $css);
    }

    public function testMasterCssCoversEveryStanceGroupInAllThreeContexts(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        foreach (Stances::groups() as $group) {
            $this->assertStringContainsString('.rel-' . $group . ' { stroke: var(--rel-' . $group . '-stroke); }', $css);
            $this->assertStringContainsString('.graph-edge-label.rel-' . $group . ' { fill: var(--rel-' . $group . '-fill); }', $css);
            $this->assertStringContainsString('.graph-legend-swatch.rel-' . $group . ' { background: var(--rel-' . $group . '-bg); }', $css);
            $this->assertStringContainsString('--rel-' . $group . '-stroke:', $css);
            $this->assertStringContainsString('--rel-' . $group . '-fill:', $css);
            $this->assertStringContainsString('--rel-' . $group . '-bg:', $css);
        }
    }

    public function testMasterCssContainsDiffClasses(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $this->assertStringContainsString('.diff-removed', $css);
        $this->assertStringContainsString('.diff-added', $css);
        $this->assertStringContainsString('.diff-same', $css);
    }

    public function testMasterCssContainsNoHardcodedStanceHex(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $lines = explode("\n", $css);
        $outsideRoot = [];
        $inRoot = false;
        foreach ($lines as $line) {
            if (str_contains($line, ':root')) {
                $inRoot = true;
                continue;
            }
            if ($inRoot && str_starts_with(trim($line), '}')) {
                $inRoot = false;
                continue;
            }
            if (!$inRoot) {
                $outsideRoot[] = $line;
            }
        }
        $relSection = implode("\n", array_filter($outsideRoot, fn (string $l): bool => str_contains($l, '.rel-') || str_contains($l, '.graph-edge-label') || str_contains($l, '.graph-legend-swatch')));
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b/', $relSection), 'stance colors must come from variables outside :root');
    }

    public function testAppJsExistsAndKeepsEditorAndSliderSeparate(): void
    {
        $this->assertFileExists($this->base . '/public/assets/js/app.js');
        $app = (string)file_get_contents($this->base . '/public/assets/js/app.js');
        $this->assertStringContainsString('ctrlKey', $app);
        $this->assertStringNotContainsString('tiptap', $app);
        $this->assertFileExists($this->base . '/public/assets/js/editor.js');
        $this->assertFileExists($this->base . '/public/assets/js/story-slider.js');
    }

    /**
     * Child-theme readiness (audit prompt 19 follow-up, prompt 20): the new
     * semantic variables exist in :root with the exact previous hardcoded
     * default values, so the default theme renders pixel-identical.
     */
    public function testRootDefinesNewThemeVariablesWithExactDefaults(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        $root = substr($css, (int)strpos($css, ':root'), (int)strpos($css, '}', (int)strpos($css, ':root')));
        $expected = [
            '--font-body: system-ui, sans-serif;',
            '--font-mono: ui-monospace, monospace;',
            '--code-bg: #f1f5f9;',
            '--highlight: #fef08a;',
            '--accent-soft: rgba(37, 99, 235, 0.12);',
            '--shadow: 0 4px 12px rgba(0, 0, 0, 0.12);',
            '--avatar-ink: #fff;',
        ];
        foreach ($expected as $line) {
            $this->assertStringContainsString($line, $root, 'missing :root definition: ' . $line);
        }
    }

    /**
     * No color or font literals outside the :root block in master.css or
     * editor.css (empty whitelist): a child theme that only overrides
     * variables can restyle the complete app, editor included.
     * font-family is allowed only as var(--...).
     */
    public function testNoColorOrFontLiteralsOutsideRootBlock(): void
    {
        $colorLiteral = '/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/';
        foreach (['master.css', 'editor.css'] as $file) {
            $css = (string)file_get_contents($this->base . '/public/assets/css/' . $file);
            $outsideRoot = $css;
            $rootPos = strpos($css, ':root');
            if ($rootPos !== false) {
                $rootEnd = strpos($css, '}', $rootPos);
                $outsideRoot = substr($css, 0, $rootPos) . substr($css, (int)$rootEnd + 1);
            }
            $this->assertSame(
                0,
                (int)preg_match($colorLiteral, $outsideRoot),
                $file . ': hardcoded color literal outside :root - route it through a variable'
            );
            // font-family is allowed outside :root only as var(--...)
            preg_match_all('/font-family\s*:\s*([^;]+);/', $outsideRoot, $fonts);
            foreach ($fonts[1] as $value) {
                $this->assertStringStartsWith('var(', trim($value), $file . ': font-family literal outside :root: ' . $value);
            }
        }
    }

    public function testEditorCssUsesOnlySharedVariables(): void
    {
        $css = (string)file_get_contents($this->base . '/public/assets/css/editor.css');
        // editor.css has no :root of its own; every var() it references must be
        // defined in master.css's :root (loaded before editor.css in the layout)
        $master = (string)file_get_contents($this->base . '/public/assets/css/master.css');
        preg_match_all('/var\((--[a-z0-9-]+)\)/', $css, $m);
        $this->assertNotEmpty($m[1]);
        foreach (array_unique($m[1]) as $var) {
            $this->assertStringContainsString($var . ':', $master, 'editor.css uses undefined variable ' . $var);
        }
    }
}
