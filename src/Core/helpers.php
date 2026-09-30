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
