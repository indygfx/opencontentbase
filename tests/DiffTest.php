<?php
declare(strict_types=1);

namespace Tests\Core;

use Core\Diff;
use PHPUnit\Framework\TestCase;

/**
 * Line-based LCS diff engine: identity, empty-input handling, single
 * line edits, multi-block changes and the whole-block fallback for
 * oversized inputs.
 */
final class DiffTest extends TestCase
{
    public function testEqualTextsProduceSingleSameSegment(): void
    {
        $this->assertSame([['type' => 'same', 'text' => "a\nb"]], Diff::compare("a\nb", "a\nb"));
    }

    public function testEmptyToTextIsOneAddedSegment(): void
    {
        $this->assertSame([['type' => 'added', 'text' => "a\nb"]], Diff::compare('', "a\nb"));
    }

    public function testTextToEmptyIsOneRemovedSegment(): void
    {
        $this->assertSame([['type' => 'removed', 'text' => "a\nb"]], Diff::compare("a\nb", ''));
    }

    public function testBothEmptyIsSingleSameSegment(): void
    {
        $this->assertSame([['type' => 'same', 'text' => '']], Diff::compare('', ''));
    }

    public function testSingleLineEdit(): void
    {
        $segments = Diff::compare("one\ntwo\nthree", "one\nTWO\nthree");
        $this->assertSame('same', $segments[0]['type']);
        $this->assertSame("one", $segments[0]['text']);
        $this->assertSame('removed', $segments[1]['type']);
        $this->assertSame("two", $segments[1]['text']);
        $this->assertSame('added', $segments[2]['type']);
        $this->assertSame("TWO", $segments[2]['text']);
        $this->assertSame('same', $segments[3]['type']);
        $this->assertSame("three", $segments[3]['text']);
    }

    public function testAddedLineInTheMiddle(): void
    {
        $segments = Diff::compare("a\nb", "a\nx\nb");
        $this->assertContains(['type' => 'added', 'text' => 'x'], $segments);
        $this->assertNotContains('removed', array_column($segments, 'type'));
    }

    public function testRemovedLineAtEnd(): void
    {
        $segments = Diff::compare("a\nb\nc", "a\nb");
        $this->assertSame([['type' => 'same', 'text' => "a\nb"], ['type' => 'removed', 'text' => 'c']], $segments);
    }

    public function testMultipleDivergentBlocksKeepOrder(): void
    {
        $segments = Diff::compare("a\nb\nc\nd", "a\nX\nc\nY");
        $this->assertSame([
            ['type' => 'same', 'text' => 'a'],
            ['type' => 'removed', 'text' => 'b'],
            ['type' => 'added', 'text' => 'X'],
            ['type' => 'same', 'text' => 'c'],
            ['type' => 'removed', 'text' => 'd'],
            ['type' => 'added', 'text' => 'Y'],
        ], $segments);
    }

    public function testLineCapFallsBackToWholeBlockReplacement(): void
    {
        $old = implode("\n", array_map(fn (int $i): string => 'old' . $i, range(1, Diff::LINE_LIMIT + 1)));
        $new = implode("\n", array_map(fn (int $i): string => 'new' . $i, range(1, Diff::LINE_LIMIT + 1)));
        $segments = Diff::compare($old, $new);
        $this->assertSame([
            ['type' => 'removed', 'text' => $old],
            ['type' => 'added', 'text' => $new],
        ], $segments);
    }

    public function testLineCapFallsBackWhenOnlyNewIsOversized(): void
    {
        $old = 'small';
        $new = implode("\n", array_map(fn (int $i): string => 'n' . $i, range(1, Diff::LINE_LIMIT + 1)));
        $segments = Diff::compare($old, $new);
        $this->assertSame('removed', $segments[0]['type']);
        $this->assertSame($old, $segments[0]['text']);
        $this->assertSame('added', $segments[1]['type']);
        $this->assertSame($new, $segments[1]['text']);
    }

    public function testContentAtLimitStillDiffs(): void
    {
        // Exactly at the cap the LCS still runs (limit is exclusive).
        $old = implode("\n", array_map(fn (int $i): string => 'l' . $i, range(1, Diff::LINE_LIMIT)));
        $segments = Diff::compare($old, $old);
        $this->assertSame([['type' => 'same', 'text' => $old]], $segments);
    }

    public function testStatsCountAddedAndRemovedLines(): void
    {
        $this->assertSame(['added' => 0, 'removed' => 0], Diff::stats("a\nb", "a\nb"));
        $this->assertSame(['added' => 2, 'removed' => 1], Diff::stats("a\nb", "a\nx\ny"));
        $this->assertSame(['added' => 0, 'removed' => 1], Diff::stats("a\nb", "a"));
        $this->assertSame(['added' => 3, 'removed' => 0], Diff::stats('', "x\ny\nz"));
    }

    public function testStatsOnOversizedInput(): void
    {
        $old = implode("\n", array_fill(0, Diff::LINE_LIMIT + 1, 'o'));
        $new = implode("\n", array_fill(0, Diff::LINE_LIMIT + 2, 'n'));
        $stats = Diff::stats($old, $new);
        $this->assertSame(Diff::LINE_LIMIT + 1, $stats['removed']);
        $this->assertSame(Diff::LINE_LIMIT + 2, $stats['added']);
    }
}
