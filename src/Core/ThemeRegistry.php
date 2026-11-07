<?php

declare(strict_types=1);

namespace Core;

/**
 * Registry for child themes. Themes are plain static folders under
 * public/assets/themes/<slug>/ with a machine-readable theme.json:
 *
 *   themes/nerdline/theme.json     {"name": ..., "version": ..., "developer": ...,
 *                                  "theme_color": ..., "background_color": ..., "icons": [...]}
 *   themes/nerdline/css/theme.css  variables-only override (never selectors)
 *   themes/nerdline/js/theme.js    optional first-party trusted JS override
 *
 * The registry scans the LIVE folder, so deployment-only themes (dropped on
 * the server, never committed - see .gitignore) appear automatically without
 * any code change. Only the "nerdline" master theme ships in the repo; it is
 * the global default (users.theme defaults to 'nerdline').
 *
 * Security: slugs are strictly validated before any path is built - never
 * pass raw user input into a path.
 */
final class ThemeRegistry
{
    /** Global default theme (shipped master theme). */
    public const DEFAULT_THEME = 'nerdline';

    /** Explicit "no theme" choice: master.css + editor.css only. */
    public const NO_THEME = 'default';

    public function __construct(private string $basePath)
    {
    }

    private function themesDir(): string
    {
        return $this->basePath . '/public/assets/themes';
    }

    public function overridesDir(): string
    {
        return $this->basePath . '/public/assets/overrides';
    }

    /** Strict slug check + the theme folder must really exist (no traversal). */
    public function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) === 1
            && is_dir($this->themesDir() . '/' . $slug);
    }

    /**
     * All installed themes with their metadata, sorted by slug. Folders with
     * invalid/missing theme.json are skipped silently - a broken theme must
     * never take the app down.
     *
     * @return list<array{slug: string, name: string, version: string, developer: string, theme_color: ?string, background_color: ?string, icons: list<array<string, string>>>}>
     */
    public function scan(): array
    {
        $themes = [];
        foreach (glob($this->themesDir() . '/*/theme.json') ?: [] as $file) {
            $slug = basename(dirname($file));
            $meta = $this->meta($slug);
            if ($meta === null) {
                continue;
            }
            $themes[] = [
                'slug' => $slug,
                'name' => $meta['name'],
                'version' => $meta['version'],
                'developer' => (string)($meta['developer'] ?? ''),
                'theme_color' => isset($meta['theme_color']) ? (string)$meta['theme_color'] : null,
                'background_color' => isset($meta['background_color']) ? (string)$meta['background_color'] : null,
                'icons' => $meta['icons'] ?? [],
            ];
        }
        usort($themes, static fn (array $a, array $b): int => strcmp($a['slug'], $b['slug']));
        return $themes;
    }

    /** Parsed theme.json or null when invalid/incomplete. @return array<string, mixed>|null */
    public function meta(string $slug): ?array
    {
        if (!$this->isValidSlug($slug)) {
            return null;
        }
        $file = $this->themesDir() . '/' . $slug . '/theme.json';
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data)
            || !isset($data['name']) || !is_string($data['name']) || $data['name'] === ''
            || !isset($data['version']) || !is_string($data['version']) || $data['version'] === ''
        ) {
            return null;
        }
        return $data;
    }

    /** Web path of the theme CSS if installed, else null. */
    public function cssPath(string $slug): ?string
    {
        return $this->assetPath($slug, 'css/theme.css');
    }

    /** Web path of the optional theme JS if installed, else null. */
    public function jsPath(string $slug): ?string
    {
        return $this->assetPath($slug, 'js/theme.js');
    }

    /** Absolute path of the theme CSS if installed, else null (for filemtime). */
    public function cssAbs(string $slug): ?string
    {
        return $this->assetAbs($slug, 'css/theme.css');
    }

    /** Absolute path of the optional theme JS if installed, else null. */
    public function jsAbs(string $slug): ?string
    {
        return $this->assetAbs($slug, 'js/theme.js');
    }

    private function assetPath(string $slug, string $rel): ?string
    {
        return $this->assetAbs($slug, $rel) !== null ? '/assets/themes/' . $slug . '/' . $rel : null;
    }

    private function assetAbs(string $slug, string $rel): ?string
    {
        if (!$this->isValidSlug($slug)) {
            return null;
        }
        $abs = $this->themesDir() . '/' . $slug . '/' . $rel;
        return is_file($abs) ? $abs : null;
    }

    /** User override CSS web path if present (gitignored dir, always wins). */
    public function overrideCssPath(): ?string
    {
        return is_file($this->overridesDir() . '/css/theme.css') ? '/assets/overrides/css/theme.css' : null;
    }

    public function overrideCssAbs(): ?string
    {
        $abs = $this->overridesDir() . '/css/theme.css';
        return is_file($abs) ? $abs : null;
    }

    public function overrideJsPath(): ?string
    {
        return is_file($this->overridesDir() . '/js/theme.js') ? '/assets/overrides/js/theme.js' : null;
    }

    public function overrideJsAbs(): ?string
    {
        $abs = $this->overridesDir() . '/js/theme.js';
        return is_file($abs) ? $abs : null;
    }

    /** The user's active theme; falls back to the global default. */
    public function activeTheme(?User $user): string
    {
        $theme = $user?->theme();
        if ($theme === null || $theme === '' || $theme === self::NO_THEME) {
            return $theme === self::NO_THEME ? self::NO_THEME : self::DEFAULT_THEME;
        }
        return $theme;
    }

    /** theme_color meta of the active theme (for the <meta name="theme-color">). */
    public function activeThemeColor(?User $user): string
    {
        $theme = $this->activeTheme($user);
        $meta = $theme === self::NO_THEME ? null : $this->meta($theme);
        return $meta['theme_color'] ?? '#16a34a';
    }
}
