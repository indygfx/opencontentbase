<?php

declare(strict_types=1);

/** @param mixed $value */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Normalizes arbitrary text into a URL slug: lowercase letters,
 * umlauts transliterated, spaces -> hyphens, everything except a-z/0-9/- removed.
 */
function slugify(string $text): string
{
    $text = trim($text);
    if (function_exists('translit_encode')) {
        $text = translit_encode($text);
    } else {
        $text = str_replace(
            ['ä', 'ö', 'ü', 'ß', 'Ä', 'Ö', 'Ü'],
            ['ae', 'oe', 'ue', 'ss', 'ae', 'oe', 'ue'],
            $text
        );
    }
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^\p{L}\p{Nd}]+/u', '-', $text) ?? '';
    $text = trim($text, '-');
    return $text;
}

/**
 * Translates a key in the given domain with {name} placeholder replacement.
 * Falls back to English and finally to the raw key.
 *
 * @param array<string, string> $params
 */
function t(string $domain, string $key, array $params = []): string
{
    $translator = \Core\Translator::instance();
    if ($translator === null) {
        $text = $key;
        foreach ($params as $name => $value) {
            $text = str_replace('{' . $name . '}', $value, $text);
        }
        return $text;
    }
    return $translator->t($domain, $key, $params);
}

/**
 * Resolves the active theme assets for the layout: the per-user theme from
 * public/assets/themes/<slug>/ (web + absolute path for the ?v=filemtime
 * cache-buster) plus the gitignored user-override dir public/assets/overrides/
 * (always loaded last, wins the cascade). 'default' theme = master.css only.
 *
 * @return array{themeCss: ?string, themeCssAbs: ?string, themeJs: ?string, themeJsAbs: ?string, themeColor: string, overridesCss: ?string, overridesCssAbs: ?string, overridesJs: ?string, overridesJsAbs: ?string}
 */
function theme_assets(?\Core\User $user = null): array
{
    $registry = new \Core\ThemeRegistry(dirname(__DIR__, 2));
    $slug = $registry->activeTheme($user);
    $isNone = $slug === \Core\ThemeRegistry::NO_THEME;
    return [
        'themeCss' => $isNone ? null : $registry->cssPath($slug),
        'themeCssAbs' => $isNone ? null : $registry->cssAbs($slug),
        'themeJs' => $isNone ? null : $registry->jsPath($slug),
        'themeJsAbs' => $isNone ? null : $registry->jsAbs($slug),
        'themeColor' => $registry->activeThemeColor($user),
        'overridesCss' => $registry->overrideCssPath(),
        'overridesCssAbs' => $registry->overrideCssAbs(),
        'overridesJs' => $registry->overrideJsPath(),
        'overridesJsAbs' => $registry->overrideJsAbs(),
    ];
}
