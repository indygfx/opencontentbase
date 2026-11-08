<?php
declare(strict_types=1);

namespace Core;

/**
 * Avatar rendering - single source for every avatar in the app (writing
 * overview stack, setup sharing block, story header stack, profile preview).
 *
 * If the user has an uploaded avatar (users.avatar = file name under
 * data/avatars/), render an <img> with a ?v=filemtime cache-buster; otherwise
 * fall back to the generated initials circle on a deterministic tone from the
 * --avatar-1..6 palette in master.css.
 */
class Avatar
{
    public const TONES = 6;

    /** Tests may point this at a temp dir so data/avatars lookups stay out of the repo. */
    public static ?string $basePathOverride = null;

    /** First letters of the name parts, max 2 chars, uppercased. */
    public static function initials(string $username): string
    {
        $parts = preg_split('/[\s_\-.]+/', trim($username)) ?: [];
        $letters = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($letters) >= 2) {
                break;
            }
        }
        if ($letters === '') {
            $letters = mb_strtoupper(mb_substr($username, 0, 1));
        }
        return $letters;
    }

    /** Deterministic tone in 1..TONES derived from the username. */
    public static function tone(string $username): int
    {
        return (abs(crc32($username)) % self::TONES) + 1;
    }

    /**
     * One avatar element: uploaded image when present, initials circle otherwise.
     * $avatarName is the users.avatar column value (bare file name), $userId
     * is used for the serving URL, $basePath for locating the stored file.
     */
    /**
     * $decorative = true renders a purely decorative avatar (alt="" +
     * aria-hidden on the image; the initials circle is always aria-hidden) -
     * used next to a visible username where the text carries the information.
     */
    public static function render(string $username, string $userId, ?string $avatarName, string $basePath, bool $decorative = false): string
    {
        if ($avatarName !== null && $avatarName !== '') {
            $store = new ImageStore(self::$basePathOverride ?? $basePath);
            $abs = $store->path('avatars', $avatarName);
            if ($abs !== null) {
                $src = '/avatars/' . rawurlencode($userId) . '?v=' . (int)filemtime($abs);
                $alt = $decorative ? ' alt="" aria-hidden="true"' : ' alt="' . e($username) . '"';
                return '<img class="avatar avatar-img" src="' . e($src) . '"' . $alt . ' loading="lazy">';
            }
        }
        return '<span class="avatar avatar-tone-' . self::tone($username) . '" aria-hidden="true">'
            . e(self::initials($username)) . '</span>';
    }
}
