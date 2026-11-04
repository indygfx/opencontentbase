<?php
declare(strict_types=1);

namespace Writing;

/**
 * Deterministic initials + color tone for the sharing avatar circles on the
 * writing overview. The tone picks one of the --avatar-1..6 palette variables
 * in master.css, so child themes can recolor the stack by overriding variables.
 */
final class Avatar
{
    public const TONES = 6;

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
}
