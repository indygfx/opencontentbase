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

    public function testNoThemeCssLinkWithoutOverrideFile(): void
    {
        $this->assertFileDoesNotExist($this->base . '/public/assets/css/theme.css');
        $this->assertStringNotContainsString('theme.css', $this->renderLayout());
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
}
