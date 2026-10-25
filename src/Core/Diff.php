<?php
declare(strict_types=1);

namespace Core;

/**
 * Pure PHP line-based diff engine (no dependencies).
 *
 * compare() returns the minimal edit script between two texts as ordered
 * segments: ['type' => 'same'|'added'|'removed', 'text' => string].
 * The algorithm is a classic line-based LCS (longest common subsequence)
 * with a dynamic-programming table.
 *
 * Safety valve: inputs with more than LINE_LIMIT lines skip the LCS
 * entirely (the table would be LINE_LIMIT^2) and degrade to a single
 * "whole block replaced" pair (removed old, added new) - the diff stays
 * correct, just not fine-grained.
 */
final class Diff
{
    /** Inputs with more lines degrade to a whole-block replacement. */
    public const LINE_LIMIT = 5000;

    /**
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    public static function compare(string $old, string $new): array
    {
        if ($old === $new) {
            return [['type' => 'same', 'text' => $old]];
        }

        $oldLines = self::splitLines($old);
        $newLines = self::splitLines($new);

        if (count($oldLines) > self::LINE_LIMIT || count($newLines) > self::LINE_LIMIT) {
            return self::wholeBlock($old, $new);
        }

        $lcs = self::lcsTable($oldLines, $newLines);
        return self::segments($oldLines, $newLines, $lcs);
    }

    /**
     * Convenience helper for history views: added/removed line counts.
     *
     * @return array{added: int, removed: int}
     */
    public static function stats(string $old, string $new): array
    {
        $added = 0;
        $removed = 0;
        foreach (self::compare($old, $new) as $segment) {
            if ($segment['type'] === 'added') {
                $added += substr_count($segment['text'], "\n") + ($segment['text'] === '' ? 0 : 1);
            } elseif ($segment['type'] === 'removed') {
                $removed += substr_count($segment['text'], "\n") + ($segment['text'] === '' ? 0 : 1);
            }
        }
        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * @return list<string>
     */
    private static function splitLines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        // A trailing newline produces an empty final element that is not
        // a real line; drop it, compare() re-attaches nothing.
        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    /**
     * Fallback for oversized inputs: the whole old block was replaced by
     * the whole new block. Both texts keep their original form.
     *
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function wholeBlock(string $old, string $new): array
    {
        $segments = [];
        if ($old !== '') {
            $segments[] = ['type' => 'removed', 'text' => $old];
        }
        if ($new !== '') {
            $segments[] = ['type' => 'added', 'text' => $new];
        }
        return $segments;
    }

    /**
     * Dynamic-programming LCS lengths table, 1-indexed:
     * table[i][j] = LCS length of oldLines[0..i-1] and newLines[0..j-1].
     *
     * @param list<string> $oldLines
     * @param list<string> $newLines
     * @return array<int, array<int, int>>
     */
    private static function lcsTable(array $oldLines, array $newLines): array
    {
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        $table = array_fill(0, $oldCount + 1, array_fill(0, $newCount + 1, 0));
        for ($i = $oldCount - 1; $i >= 0; $i--) {
            for ($j = $newCount - 1; $j >= 0; $j--) {
                if ($oldLines[$i] === $newLines[$j]) {
                    $table[$i][$j] = $table[$i + 1][$j + 1] + 1;
                } else {
                    $table[$i][$j] = max($table[$i + 1][$j], $table[$i][$j + 1]);
                }
            }
        }
        return $table;
    }

    /**
     * Walks the LCS table to produce the ordered segment list: removed
     * lines first, then added lines, at every divergence point.
     *
     * @param list<string> $oldLines
     * @param list<string> $newLines
     * @param array<int, array<int, int>> $table
     * @return list<array{type: 'same'|'added'|'removed', text: string}>
     */
    private static function segments(array $oldLines, array $newLines, array $table): array
    {
        $segments = [];
        $push = function (string $type, array $lines) use (&$segments): void {
            if ($lines === []) {
                return;
            }
            $segments[] = ['type' => $type, 'text' => implode("\n", $lines)];
        };

        $i = 0;
        $j = 0;
        $same = [];
        $removed = [];
        $added = [];
        $oldCount = count($oldLines);
        $newCount = count($newLines);
        while ($i < $oldCount || $j < $newCount) {
            if ($i < $oldCount && $j < $newCount && $oldLines[$i] === $newLines[$j]) {
                // Flush pending divergent segments before a run of
                // unchanged lines (removals first, then additions).
                $push('removed', $removed);
                $push('added', $added);
                $removed = [];
                $added = [];
                $same[] = $oldLines[$i];
                $i++;
                $j++;
                continue;
            }
            // Flush the same-run before starting a divergent block.
            $push('same', $same);
            $same = [];
            if ($j >= $newCount || ($i < $oldCount && $table[$i + 1][$j] >= $table[$i][$j + 1])) {
                $removed[] = $oldLines[$i];
                $i++;
            } else {
                $added[] = $newLines[$j];
                $j++;
            }
        }
        $push('removed', $removed);
        $push('added', $added);
        $push('same', $same);
        return $segments;
    }
}
