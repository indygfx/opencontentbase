<?php

declare(strict_types=1);

namespace Tests\Theming;

use PHPUnit\Framework\TestCase;

/**
 * The shipped child theme "Nerdline" (nerdline media solutions): it overrides
 * ONLY :root variables in public/assets/themes/nerdline/css/theme.css - no selectors, no rules.
 * This doubles as the acceptance test for the theming system: master.css
 * provides the new structural variables with defaults equal to the previous
 * hardcoded values, so the default theme stays pixel-identical, and the theme
 * file meets WCAG AA contrast on its own color pairs.
 */
final class NerdlineThemeTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = dirname(__DIR__);
    }

    private function themeCss(): string
    {
        return (string)file_get_contents($this->base . '/public/assets/themes/nerdline/css/theme.css');
    }

    private function masterCss(): string
    {
        return (string)file_get_contents($this->base . '/public/assets/css/master.css');
    }

    /** Strip the (single) :root block, return what remains. */
    private function outsideRoot(string $css): string
    {
        $pos = strpos($css, ':root');
        if ($pos === false) {
            return $css;
        }
        $end = strpos($css, '}', $pos);
        return substr($css, 0, $pos) . substr($css, (int)$end + 1);
    }

    public function testPrerequisitePrompt20VariablesExist(): void
    {
        $root = substr($this->masterCss(), 0, (int)strpos($this->masterCss(), '}', (int)strpos($this->masterCss(), ':root')));
        foreach (['--code-bg', '--highlight', '--accent-soft', '--shadow', '--font-body', '--font-mono', '--avatar-ink'] as $var) {
            $this->assertStringContainsString($var . ':', $root, 'prerequisite variable missing: ' . $var);
        }
    }

    public function testThemeFileIsHeaderCommentPlusOneRootBlockOnly(): void
    {
        $css = $this->themeCss();
        $lines = explode("\n", $css);
        $this->assertSame('/* Nerdline theme – nerdline media solutions. Overrides :root variables only. */', $lines[0], 'line 1 must be the Nerdline header comment');
        $rest = implode("\n", array_slice($lines, 1));
        $this->assertMatchesRegularExpression('/^\s*:root\s*\{[^}]*\}\s*$/s', $rest, 'theme.css must contain ONLY one :root block - no selectors, no rules (override variables, never classes)');
    }

    public function testThemeHasNoColorLiteralsOutsideRoot(): void
    {
        $outside = $this->outsideRoot($this->themeCss());
        $this->assertSame(0, (int)preg_match('/#[0-9a-fA-F]{3,8}\b|rgba?\(|hsla?\(/', $outside), 'theme.css: color literals outside :root');
    }

    public function testThemeKeyValues(): void
    {
        $theme = $this->themeCss();
        foreach ([
            '--accent: #66BB6A;',
            '--link: #1B5E20;',
            '--topbar-bg: #66BB6A;',
            '--topbar-ink: #0B3D12;',
            '--button-ink: #0B3D12;',
            '--button-padding: 0 20px;',
            '--button-width: auto;',
            '--slider-from: #C8E6C9;',
            '--slider-to: #C8E6C9;',
            '--code-bg: #E8F5E9;',
            '--highlight: #DCEDC8;',
            '--accent-soft: rgba(102, 187, 106, 0.12);',
        ] as $line) {
            $this->assertStringContainsString($line, $theme, 'theme override missing: ' . $line);
        }
    }

    public function testMasterDefaultsKeepDefaultThemePixelIdentical(): void
    {
        $css = $this->masterCss();
        $root = substr($css, (int)strpos($css, ':root'), (int)strpos($css, '}', (int)strpos($css, ':root')));
        foreach ([
            '--topbar-bg: #fff;',
            '--topbar-ink: #2563eb;',
            '--link: #1f2430;',
            '--button-ink: #fff;',
            '--button-padding: 0.5rem 1rem;',
            '--button-width: auto;',
        ] as $line) {
            $this->assertStringContainsString($line, $root, 'master default missing (pixel-identity): ' . $line);
        }
        // the new variables are actually wired into the rules
        $this->assertMatchesRegularExpression('/\.topbar \{[^}]*background: var\(--topbar-bg\);/', $css);
        $this->assertMatchesRegularExpression('/\.topbar nav a \{[^}]*color: var\(--topbar-ink\);/', $css);
        $this->assertStringContainsString('main a { color: var(--link); }', $css);
        $this->assertMatchesRegularExpression('/button, \.button \{[^}]*color: var\(--button-ink\);[^}]*padding: var\(--button-padding\);[^}]*width: var\(--button-width\);/', $css);
        // phone rule routes the full-width button behavior through the variable
        $phone = substr($css, (int)strpos($css, '@media (max-width: 480px)'));
        $this->assertStringContainsString(':root { --button-width: 100%; }', $phone);
    }

    public function testThemeColorPairsMeetWcagAa(): void
    {
        $theme = $this->themeCss();
        $get = function (string $var) use ($theme): string {
            preg_match('/' . preg_quote($var, '/') . ':\s*([^;]+);/', $theme, $m);
            $this->assertNotEmpty($m, 'theme var missing: ' . $var);
            return trim($m[1]);
        };
        $pairs = [
            'button ink on accent' => [$get('--button-ink'), $get('--accent')],
            'topbar ink on topbar bg' => [$get('--topbar-ink'), $get('--topbar-bg')],
            'link on white' => [$get('--link'), '#ffffff'],
            'link on page bg' => [$get('--link'), '#f8fafc'],
            'slider text on slider bg' => [$get('--slider-text'), $get('--slider-from')],
        ];
        foreach ($pairs as $label => [$fg, $bg]) {
            $ratio = $this->contrastRatio($fg, $bg);
            $this->assertGreaterThanOrEqual(4.5, $ratio, "$label: $fg on $bg = " . round($ratio, 2) . ':1 (WCAG AA needs 4.5:1)');
        }
    }

    /** WCAG relative luminance + contrast ratio for hex colors. */
    private function contrastRatio(string $hexA, string $hexB): float
    {
        $lum = function (string $hex): float {
            $hex = ltrim($hex, '#');
            $r = hexdec(substr($hex, 0, 2)) / 255;
            $g = hexdec(substr($hex, 2, 2)) / 255;
            $b = hexdec(substr($hex, 4, 2)) / 255;
            $lin = static fn (float $c): float => $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
        };
        $a = $lum($hexA);
        $b = $lum($hexB);
        [$hi, $lo] = $a >= $b ? [$a, $b] : [$b, $a];
        return ($hi + 0.05) / ($lo + 0.05);
    }
}
