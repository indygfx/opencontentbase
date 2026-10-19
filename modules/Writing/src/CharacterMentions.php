<?php
declare(strict_types=1);

namespace Writing;

use Core\Database;

/**
 * Mention-based character weighting: counts case-insensitive occurrences
 * of each character title in the chapter prose (latest revision per
 * chapter only). Counts are cached in character_mention_counts; a recount
 * happens on demand only when the chapter prose changed since the last
 * count (cheap fingerprint: MAX revision created_at + chapter count).
 */
final class CharacterMentions
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Maps a mention count to a 0-10 level (0 = never mentioned,
     * 10 = protagonist-level). The single authoritative formula.
     */
    public static function levelFor(int $mentions): int
    {
        if ($mentions <= 0) {
            return 0;
        }
        return min(10, (int)round(sqrt($mentions) * 1.5));
    }

    /**
     * Cached mention counts per character for a story, keyed by character
     * id. Recomputes (and rewrites the cache) only when the chapter prose
     * changed since the last count; reading never recomputes.
     *
     * @return array<string, int>
     */
    public function counts(string $storyId): array
    {
        if ($this->fingerprint($storyId) === $this->cachedFingerprint($storyId)) {
            return $this->readCounts($storyId);
        }
        $this->recount($storyId);
        return $this->readCounts($storyId);
    }

    /** Cached level per character id for a story (no recount). @return array<string, int> */
    public function levels(string $storyId): array
    {
        $levels = [];
        foreach ($this->counts($storyId) as $characterId => $mentions) {
            $levels[$characterId] = self::levelFor($mentions);
        }
        return $levels;
    }

    /**
     * Cheap change fingerprint of a story's chapter prose:
     * latest revision timestamp + number of chapters.
     */
    public function fingerprint(string $storyId): string
    {
        $max = (string)($this->db->one(
            "SELECT MAX(r.created_at) AS m
             FROM content_revisions r
             JOIN chapters c ON c.id = r.object_id
             WHERE c.project_id = ?",
            [$storyId]
        )['m'] ?? '');
        $chapterCount = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM chapters WHERE project_id = ?',
            [$storyId]
        )['c'];
        return $max . '|' . $chapterCount;
    }

    /**
     * Recomputes and stores the mention counts for a story.
     *
     * @return array<string, int> character id => total mentions
     */
    public function recount(string $storyId): array
    {
        $titles = $this->characterTitles($storyId);
        $prose = $this->chapterProse($storyId);
        $counts = [];
        foreach ($titles as $characterId => $title) {
            $needle = mb_strtolower($title);
            $total = 0;
            if ($needle !== '') {
                foreach ($prose as $body) {
                    $total += self::occurrences(mb_strtolower($body), $needle);
                }
            }
            $counts[$characterId] = $total;
        }
        $this->db->transaction(function (Database $db) use ($storyId, $counts): void {
            $db->run('DELETE FROM character_mention_counts WHERE story_id = ?', [$storyId]);
            foreach ($counts as $characterId => $total) {
                $db->run(
                    'INSERT INTO character_mention_counts (story_id, character_id, total_mentions)
                     VALUES (?, ?, ?)',
                    [$storyId, $characterId, $total]
                );
            }
            $db->run('DELETE FROM character_mention_meta WHERE story_id = ?', [$storyId]);
            $db->run(
                'INSERT INTO character_mention_meta (story_id, fingerprint) VALUES (?, ?)',
                [$storyId, $this->fingerprint($storyId)]
            );
        });
        return $counts;
    }

    /** Case-insensitive, non-overlapping occurrences of needle in haystack. */
    private static function occurrences(string $haystack, string $needle): int
    {
        if ($needle === '') {
            return 0;
        }
        $count = 0;
        $offset = 0;
        while (($pos = mb_strpos($haystack, $needle, $offset)) !== false) {
            $count++;
            $offset = $pos + mb_strlen($needle);
        }
        return $count;
    }

    /** @return array<string, int> character id => cached total mentions */
    private function readCounts(string $storyId): array
    {
        $counts = [];
        foreach ($this->db->all(
            'SELECT character_id, total_mentions FROM character_mention_counts WHERE story_id = ?',
            [$storyId]
        ) as $row) {
            $counts[(string)$row['character_id']] = (int)$row['total_mentions'];
        }
        return $counts;
    }

    private function cachedFingerprint(string $storyId): ?string
    {
        $row = $this->db->one(
            'SELECT fingerprint FROM character_mention_meta WHERE story_id = ?',
            [$storyId]
        );
        return $row === null ? null : (string)$row['fingerprint'];
    }

    /** @return array<string, string> character id => current title */
    private function characterTitles(string $storyId): array
    {
        $titles = [];
        foreach ($this->db->all(
            "SELECT k.id, COALESCE(r.title, o.slug) AS title
             FROM characters k
             JOIN content_objects o ON o.id = k.id AND o.type = 'character'
             LEFT JOIN content_revisions r ON r.object_id = k.id
              AND r.created_at = (
                  SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = k.id
              )
             WHERE k.project_id = ?
             ORDER BY title",
            [$storyId]
        ) as $row) {
            $titles[(string)$row['id']] = (string)$row['title'];
        }
        return $titles;
    }

    /** @return list<string> latest revision body per chapter of the story */
    private function chapterProse(string $storyId): array
    {
        $bodies = [];
        foreach ($this->db->all(
            "SELECT r.body
             FROM chapters c
             JOIN content_objects o ON o.id = c.id AND o.type = 'chapter'
             LEFT JOIN content_revisions r ON r.object_id = c.id
              AND r.id = (
                  SELECT r2.id FROM content_revisions r2
                  WHERE r2.object_id = c.id
                  ORDER BY r2.created_at DESC, r2.rowid DESC LIMIT 1
              )
             WHERE c.project_id = ?",
            [$storyId]
        ) as $row) {
            $bodies[] = (string)$row['body'];
        }
        return $bodies;
    }
}
