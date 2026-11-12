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

/**
 * Single-source avatar element for templates: uploaded image when the user
 * has one (users.avatar), initials circle otherwise. Used by the writing
 * overview stack, the setup sharing block, the story header stack and the
 * profile preview.
 */
function avatar_tag(string $username, string $userId, ?string $avatarName, bool $decorative = false): string
{
    return \Core\Avatar::render($username, $userId, $avatarName, dirname(__DIR__, 2), $decorative);
}

/**
 * Render Markdown to safe HTML (league/commonmark, already a runtime dep).
 * SECURITY: raw HTML is escaped first (html_input=escape) - user-supplied HTML
 * never passes through; unsafe link schemes (javascript:, data:) are dropped
 * (allow_unsafe_links=false); every link gets rel="noopener".
 */
function markdown_html(string $md): string
{
    static $converter = null;
    if ($converter === null) {
        $converter = new \League\CommonMark\GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'external_link' => [
                'internal_hosts' => [], // every link counts as external -> rel="noopener"
                'open_in_new_window' => false,
                'noopener' => 'all', // rel=noopener on every rendered link
            ],
        ]);
        $converter->getEnvironment()->addExtension(new \League\CommonMark\Extension\ExternalLink\ExternalLinkExtension());
    }
    return $converter->convert($md)->getContent();
}

/**
 * Dashboard text block: full Markdown-rendered text; when the word count exceeds
 * the personal target from Novel Setup, the block is collapsed (CSS line-clamp)
 * and gets a Show more/Show less toggle (aria-expanded, 44px, .button-secondary).
 * targetWords <= 0 -> always shown in full (e.g. the idea, which has no word target).
 */
function read_more_block(string $md, int $targetWords): string
{
    $html = markdown_html($md);
    $plain = trim(strip_tags($html));
    $words = $plain === '' ? 0 : count(preg_split('/\s+/u', $plain, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    if ($targetWords <= 0 || $words <= $targetWords) {
        return '<div class="md-block">' . $html . '</div>';
    }
    return '<div class="md-block read-more" data-collapsed="1">'
        . '<div class="read-more__clamp">' . $html . '</div>'
        . '<button type="button" class="button button-secondary read-more__toggle" aria-expanded="false"'
        . ' data-more="' . e(t('writing', 'show_more')) . '" data-less="' . e(t('writing', 'show_less')) . '">'
        . e(t('writing', 'show_more')) . '</button>'
        . '</div>';
}
