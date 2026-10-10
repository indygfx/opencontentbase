<?php declare(strict_types=1);

namespace Writing;

use Core\Database;

/**
 * Server-side progress calculation for the story dashboard.
 *
 * Computes the ratio of actual value / target value for every dashboard
 * section. Pure calculation: no labels, no localization - the UI layer
 * formats the returned numbers. Ratios are capped at 1.0 and never
 * negative. Results are recomputed on every request (SQLite is fast
 * enough); no persistence, no caching.
 */
final class ProjectProgress
{
    private const GENRE_WORD_TARGETS = [
        'Fantasy' => 110000,
        'Sci-Fi' => 100000,
        'Romance' => 80000,
        'Thriller/Crime' => 90000,
        'Young Adult' => 70000,
        'General' => 90000,
    ];

    public const GENRES = ['General', 'Fantasy', 'Sci-Fi', 'Romance', 'Thriller/Crime', 'Young Adult'];

    public function __construct(private Database $db)
    {
    }

    /**
     * Total word target for a genre (falls back to General for unknown genres).
     */
    public static function genreWordTarget(string $genre): int
    {
        return self::GENRE_WORD_TARGETS[$genre] ?? self::GENRE_WORD_TARGETS['General'];
    }

    /**
     * One entry per dashboard section:
     * ['ratio' => 0.0-1.0, 'actual' => int, 'target' => int, 'done' => bool]
     *
     * 'idea' is binary (saved = done); all others are value/target ratios.
     *
     * @return array<string, array{ratio: float, actual: int, target: int, done: bool}>
     */
    public function calculate(string $projectId): array
    {
        $project = $this->db->one(
            'SELECT id, idea, blurb, synopsis_long FROM projects WHERE id = ?',
            [$projectId]
        );
        $targets = $this->targets($projectId);

        $blurbWords = self::words((string)($project['blurb'] ?? ''));
        $synopsisWords = self::words((string)($project['synopsis_long'] ?? ''));
        $chapterCount = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM chapters WHERE project_id = ?',
            [$projectId]
        )['c'];
        $chapterWords = $this->chapterWords($projectId);
        $characterCount = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM characters WHERE project_id = ?',
            [$projectId]
        )['c'];
        $relationCount = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM character_relations WHERE project_id = ?',
            [$projectId]
        )['c'];
        $backgroundCount = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM backgrounds WHERE project_id = ?',
            [$projectId]
        )['c'];
        $synopsisCount = (int)$this->db->one(
            'SELECT COUNT(*) AS c FROM chapter_synopses WHERE project_id = ?',
            [$projectId]
        )['c'];

        // (a): relations target = min characters * relations per character
        $relationTarget = max(1, $targets['target_min_characters'] * $targets['target_min_relations']);

        return [
            'idea' => [
                'ratio' => trim((string)($project['idea'] ?? '')) !== '' ? 1.0 : 0.0,
                'actual' => trim((string)($project['idea'] ?? '')) !== '' ? 1 : 0,
                'target' => 1,
                'done' => trim((string)($project['idea'] ?? '')) !== '',
            ],
            'blurb' => [
                'ratio' => self::ratio($blurbWords, $targets['target_words_blurb']),
                'actual' => $blurbWords,
                'target' => $targets['target_words_blurb'],
                'done' => $blurbWords >= $targets['target_words_blurb'],
            ],
            'synopsis' => [
                'ratio' => self::ratio($synopsisWords, $targets['target_words_synopsis']),
                'actual' => $synopsisWords,
                'target' => $targets['target_words_synopsis'],
                'done' => $synopsisWords >= $targets['target_words_synopsis'],
            ],
            'outlines' => [
                'ratio' => self::ratio($synopsisCount, $targets['target_chapters']),
                'actual' => $synopsisCount,
                'target' => $targets['target_chapters'],
                'done' => $synopsisCount >= $targets['target_chapters'],
            ],
            'chapters' => [
                'ratio' => self::ratio($chapterWords, $targets['target_words_total']),
                'actual' => $chapterWords,
                'target' => $targets['target_words_total'],
                'done' => $chapterWords >= $targets['target_words_total'],
            ],
            'characters' => [
                'ratio' => self::ratio($characterCount, $targets['target_min_characters']),
                'actual' => $characterCount,
                'target' => $targets['target_min_characters'],
                'done' => $characterCount >= $targets['target_min_characters'],
            ],
            'relations' => [
                'ratio' => self::ratio($relationCount, $relationTarget),
                'actual' => $relationCount,
                'target' => $relationTarget,
                'done' => $relationCount >= $relationTarget,
            ],
            'backgrounds' => [
                'ratio' => self::ratio($backgroundCount, $targets['target_backgrounds']),
                'actual' => $backgroundCount,
                'target' => $targets['target_backgrounds'],
                'done' => $backgroundCount >= $targets['target_backgrounds'],
            ],
        ];
    }

    /**
     * Weighted overall progress across all sections (0.0-1.0).
     *
     * Weights: idea 5%, blurb 10%, synopsis 10%, outlines 15%,
     * chapters 30%, characters 10%, relations 10%, backgrounds 10%.
     */
    public function overall(string $projectId): float
    {
        $weights = [
            'idea' => 0.05,
            'blurb' => 0.10,
            'synopsis' => 0.10,
            'outlines' => 0.15,
            'chapters' => 0.30,
            'characters' => 0.10,
            'relations' => 0.10,
            'backgrounds' => 0.10,
        ];
        $progress = $this->calculate($projectId);
        $sum = 0.0;
        foreach ($weights as $section => $weight) {
            $sum += $progress[$section]['ratio'] * $weight;
        }
        return min(1.0, $sum);
    }

    /**
     * Saved targets for a project; falls back to column defaults when
     * no row exists yet.
     *
     * @return array{target_chapters: int, target_words_total: int, target_words_blurb: int, target_words_synopsis: int, target_min_characters: int, target_min_relations: int, target_backgrounds: int}
     */
    public function targets(string $projectId): array
    {
        $row = $this->db->one('SELECT * FROM project_targets WHERE project_id = ?', [$projectId]);
        return [
            'target_chapters' => (int)($row['target_chapters'] ?? 40),
            'target_words_total' => (int)($row['target_words_total'] ?? 90000),
            'target_words_blurb' => (int)($row['target_words_blurb'] ?? 150),
            'target_words_synopsis' => (int)($row['target_words_synopsis'] ?? 600),
            'target_min_characters' => (int)($row['target_min_characters'] ?? 3),
            'target_min_relations' => (int)($row['target_min_relations'] ?? 2),
            'target_backgrounds' => (int)($row['target_backgrounds'] ?? 4),
        ];
    }

    /**
     * Word count of a raw markdown text. Internal [[type:slug]] link
     * syntax is stripped before counting; words are whitespace-separated
     * tokens.
     */
    public static function words(string $markdown): int
    {
        $text = preg_replace('/\\[\\[[^\\]]+\\]\\]/', ' ', $markdown) ?? $markdown;
        $words = preg_split('/\\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        return $words === false ? 0 : count($words);
    }

    /**
     * Total word count of the latest revision of every written chapter
     * in the project.
     */
    private function chapterWords(string $projectId): int
    {
        $rows = $this->db->all(
            "SELECT r.body FROM content_revisions r
             JOIN chapters c ON c.id = r.object_id
             WHERE c.project_id = ?
               AND r.created_at = (
                   SELECT MAX(r2.created_at) FROM content_revisions r2 WHERE r2.object_id = r.object_id
               )",
            [$projectId]
        );
        $sum = 0;
        foreach ($rows as $row) {
            $sum += self::words((string)$row['body']);
        }
        return $sum;
    }

    private static function ratio(int $actual, int $target): float
    {
        if ($target <= 0) {
            return 0.0;
        }
        return min(1.0, max(0.0, $actual / $target));
    }
}
