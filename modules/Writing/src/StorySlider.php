<?php

declare(strict_types=1);

namespace Writing;

use Core\Database;
use Core\User;

/**
 * Builds the story excerpt slider for the writing dashboard.
 *
 * Only chapters of projects owned by the user qualify. A chapter qualifies
 * when it has more than three paragraphs. One paragraph is picked at random
 * per qualifying chapter, truncated to 80 words, and at most three chapters
 * are shown. The selection is seeded, so it stays stable within a session.
 */
final class StorySlider
{
    private const MAX_SLIDES = 3;
    private const MAX_WORDS = 80;
    private const MIN_PARAGRAPHS = 3;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param string $seed stable per-session seed (session CSRF token)
     * @return list<array{project_id: string, project_title: string, chapter_slug: string, chapter_title: string, text: string}>
     */
    public function slides(User $user, string $seed): array
    {
        $chapters = $this->qualifyingChapters($user);
        if ($chapters === []) {
            return [];
        }
        $seed = $seed !== '' ? $seed : 'default';
        $state = crc32($seed);
        $order = $this->shuffleKeys(array_keys($chapters), $state);
        $slides = [];
        foreach ($order as $key) {
            if (count($slides) >= self::MAX_SLIDES) {
                break;
            }
            $chapter = $chapters[$key];
            $paragraphs = $chapter['paragraphs'];
            $index = $this->rand($state, count($paragraphs));
            $text = $this->excerpt($paragraphs[$index], self::MAX_WORDS);
            if ($text === '') {
                continue;
            }
            $slides[] = [
                'project_id' => $chapter['project_id'],
                'project_title' => $chapter['project_title'],
                'chapter_slug' => $chapter['chapter_slug'],
                'chapter_title' => $chapter['chapter_title'],
                'text' => $text,
            ];
        }
        return $slides;
    }

    /**
     * @return array<string, array{project_id: string, project_title: string, chapter_slug: string, chapter_title: string, paragraphs: list<string>}>
     */
    private function qualifyingChapters(User $user): array
    {
        $rows = $this->db->all(
            "SELECT p.id AS project_id, p.title AS project_title,
                    o.slug AS chapter_slug,
                    COALESCE(s.title, o.slug) AS chapter_title,
                    COALESCE(r.body, '') AS body
             FROM projects p
             JOIN chapters c ON c.project_id = p.id
             JOIN content_objects o ON o.id = c.id AND o.type = 'chapter'
             LEFT JOIN chapter_synopses s ON s.id = c.chapter_synopsis_id
             LEFT JOIN content_revisions r ON r.object_id = o.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = o.id
              )
             WHERE p.owner_id = ?
             ORDER BY p.created_at ASC, c.created_at ASC, o.slug ASC",
            [$user->id()]
        );
        $chapters = [];
        foreach ($rows as $row) {
            $paragraphs = $this->paragraphs((string)$row['body']);
            if (count($paragraphs) <= self::MIN_PARAGRAPHS) {
                continue;
            }
            $key = $row['project_id'] . '/' . $row['chapter_slug'];
            $chapters[$key] = [
                'project_id' => (string)$row['project_id'],
                'project_title' => (string)$row['project_title'],
                'chapter_slug' => (string)$row['chapter_slug'],
                'chapter_title' => (string)$row['chapter_title'],
                'paragraphs' => $paragraphs,
            ];
        }
        return $chapters;
    }

    /** @return list<string> */
    private function paragraphs(string $body): array
    {
        $parts = preg_split('/\n\s*\n/', trim($body)) ?: [];
        $paragraphs = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $text = $this->plain($part);
            if ($text === '') {
                continue;
            }
            $paragraphs[] = $part;
        }
        return $paragraphs;
    }

    private function excerpt(string $paragraph, int $maxWords): string
    {
        $text = $this->plain($paragraph);
        if ($text === '') {
            return '';
        }
        preg_match_all('/\S+/u', $text, $matches);
        $words = $matches[0] ?? [];
        if (count($words) <= $maxWords) {
            return $text;
        }
        $cut = implode(' ', array_slice($words, 0, $maxWords));
        $end = strrpos($cut . ' ', '. ');
        if ($end !== false && $end > (int)(mb_strlen($cut) * 0.5)) {
            return substr($cut, 0, $end + 1);
        }
        return rtrim($cut, " ,;:-") . ' …';
    }

    private function plain(string $markdown): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/`([^`]*)`/', '$1', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/\[\[[^]]*\]\]/', ' ', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}>\s?/m', '', $text) ?? $text;
        $text = preg_replace('/^\s{0,3}[-*+]\s+/m', '', $text) ?? $text;
        $text = preg_replace('/(\*\*|__|\*|_|~~)/', '', $text) ?? $text;
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private function shuffleKeys(array $keys, int &$state): array
    {
        $keys = array_values($keys);
        for ($i = count($keys) - 1; $i > 0; $i--) {
            $j = $this->rand($state, $i + 1);
            [$keys[$i], $keys[$j]] = [$keys[$j], $keys[$i]];
        }
        return $keys;
    }

    private function rand(int &$state, int $max): int
    {
        $state = ($state * 1103515245 + 12345) & 0x7fffffff;
        return $max <= 1 ? 0 : $state % $max;
    }
}
