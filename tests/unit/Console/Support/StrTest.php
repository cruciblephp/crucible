<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Support;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Framework\TestCase;

use function mb_chr;
use function sprintf;

/**
 * The width engine under every table, cell, prompt and wrapped line.
 *
 * ⚠ It had NO test until 2026-09-18. `crucible mutate` reported 62
 * escaped mutants here — more than any other file — and they were all
 * `comparison` and `logical` on the Unicode range tables, which is
 * exactly what an untested lookup table looks like: every `>=` could be
 * `>` and nothing noticed.
 */
#[CoversClass(Str::class)]
final class StrTest extends TestCase
{
    /**
     * The same tables Str declares, written again on purpose.
     *
     * A boundary test that reads the ranges from the class under test
     * asserts only that the class agrees with itself. Restating them is
     * what makes a moved edge a failure rather than a silent change, and
     * it is the only form that kills a flipped comparison.
     *
     * @var list<array{int, int}>
     */
    private const array WIDE = [
        [0x1100, 0x115F],   // Hangul Jamo
        [0x2E80, 0x303E],   // CJK radicals, Kangxi
        [0x3041, 0x33FF],   // Hiragana .. CJK symbols
        [0x3400, 0x4DBF],   // CJK Ext A
        [0x4E00, 0x9FFF],   // CJK Unified
        [0xA000, 0xA4CF],   // Yi
        [0xAC00, 0xD7A3],   // Hangul syllables
        [0xF900, 0xFAFF],   // CJK compatibility
        [0xFE30, 0xFE4F],   // CJK compatibility forms
        [0xFF00, 0xFF60],   // fullwidth forms
        [0xFFE0, 0xFFE6],   // fullwidth signs
        [0x1F300, 0x1FAFF], // emoji & symbols
        [0x20000, 0x3FFFD], // CJK Ext B+
    ];

    /** @var list<array{int, int}> */
    private const array ZERO = [
        [0x200D, 0x200D], // zero-width joiner
        [0x0300, 0x036F], // combining diacritics
        [0x200B, 0x200F], // zero-width spaces/marks
        [0xFE00, 0xFE0F], // variation selectors
    ];

    /**
     * Every edge of every range, plus the codepoint just outside it.
     *
     * The outside probe is judged against these tables rather than
     * assumed to be narrow, because several ranges are adjacent —
     * 0x33FF is followed immediately by 0x3400, both wide — and an
     * "outside must be 1" rule would be wrong there rather than strict.
     */
    public function testEveryWidthRangeEdgeHoldsOnBothSides(): void
    {
        foreach (self::WIDE as [$low, $high]) {
            $this->assertWidthAt($low, 2);
            $this->assertWidthAt($high, 2);
            $this->assertWidthAt($low - 1, $this->expected($low - 1));
            $this->assertWidthAt($high + 1, $this->expected($high + 1));
        }

        foreach (self::ZERO as [$low, $high]) {
            $this->assertWidthAt($low, 0);
            $this->assertWidthAt($high, 0);
            $this->assertWidthAt($low - 1, $this->expected($low - 1));
            $this->assertWidthAt($high + 1, $this->expected($high + 1));
        }
    }

    /** A variation selector adds no column to the emoji it follows. */
    public function testAnEmojiPresentationSequenceIsOneEmojiWide(): void
    {
        self::assertSame(0, Str::charWidth("\u{FE0F}"));
        self::assertSame(2, Str::width("\u{1F600}\u{FE0F}"), 'the selector is free');
    }

    /**
     * ⚠ The wide table skips U+2600–U+27BF, and these are Wide in Unicode.
     *
     * ✓ Measured 2026-09-18: `✋` (U+270B) and `⚠` (U+26A0) are
     * East_Asian_Width=Wide, and most terminals draw them in two columns,
     * but `isWide()` starts its emoji range at U+1F300 — so Str calls
     * them one column and a table containing one loses alignment by a
     * column per occurrence.
     *
     * Pinned rather than fixed: widening the table changes every
     * measured column in the console at once, which is a rendering
     * decision rather than a test's to take. This test is here so the
     * limitation is NAMED, and so the day it is widened, this is what
     * says so out loud.
     */
    public function testDingbatsBelowTheEmojiRangeMeasureOneColumn(): void
    {
        self::assertSame(1, Str::charWidth('✋'), 'U+270B, Wide in Unicode');
        self::assertSame(1, Str::charWidth('⚠'), 'U+26A0, Wide in Unicode');
        self::assertSame(2, Str::charWidth('😀'), 'U+1F600, where the table does start');
    }

    public function testCharWidthOfNothingIsZero(): void
    {
        self::assertSame(0, Str::charWidth(''));
    }

    public function testWidthCountsColumnsNotCharacters(): void
    {
        self::assertSame(0, Str::width(''));
        self::assertSame(5, Str::width('plain'));
        self::assertSame(6, Str::width('日本語'), 'three CJK graphemes are six columns');
        self::assertSame(7, Str::width('a日本語'), 'mixed widths add up');
        self::assertSame(1, Str::width("e\u{0301}"), 'a combining acute adds no column');
    }

    public function testWidthIgnoresAnsiSequences(): void
    {
        self::assertSame(3, Str::width("\e[31mred\e[0m"));
        self::assertSame('red', Str::stripAnsi("\e[31mred\e[0m"));
        self::assertSame('plain', Str::stripAnsi('plain'));
    }

    public function testLengthCountsCharactersAndNotColumns(): void
    {
        // The counterpart to width(): 3 characters, 6 columns. A caller
        // reaching for the wrong one is the bug this pair documents.
        self::assertSame(3, Str::length('日本語'));
        self::assertSame(6, Str::width('日本語'));
        self::assertSame(['日', '本', '語'], Str::graphemes('日本語'));
    }

    public function testTruncateKeepsWhatFitsAndMarksWhatItCut(): void
    {
        self::assertSame('', Str::truncate('anything', 0), 'no room is no output');
        self::assertSame('', Str::truncate('anything', -1));
        self::assertSame('abcde', Str::truncate('abcde', 5), 'exactly the width is untouched');
        self::assertSame('abcde', Str::truncate('abcde', 6));
        self::assertSame('abcd…', Str::truncate('abcdef', 5), 'the ellipsis is inside the budget');
        self::assertSame('ab--', Str::truncate('abcdef', 4, '--'), 'a wider ellipsis keeps less');
    }

    public function testPaddingMeasuresColumnsNotBytes(): void
    {
        self::assertSame('ab   ', Str::padRight('ab', 5));
        self::assertSame('   ab', Str::padLeft('ab', 5));
        self::assertSame(' ab  ', Str::padBoth('ab', 5), 'the odd column goes right');
        self::assertSame(' ab ', Str::padBoth('ab', 4));

        // The reason this class exists: two columns per CJK grapheme, so
        // 日本 in a 6-wide column takes two spaces, not four.
        self::assertSame('日本  ', Str::padRight('日本', 6));
        self::assertSame('  日本', Str::padLeft('日本', 6));
    }

    public function testPaddingAtOrOverTheWidthIsLeftAlone(): void
    {
        self::assertSame('abcde', Str::padRight('abcde', 5));
        self::assertSame('abcde', Str::padLeft('abcde', 5));
        self::assertSame('abcde', Str::padBoth('abcde', 5));
        self::assertSame('abcdef', Str::padRight('abcdef', 5), 'already over is never trimmed');
        self::assertSame('abcdef', Str::padBoth('abcdef', 5));
    }

    public function testWrapBreaksOnWordsAndKeepsParagraphs(): void
    {
        self::assertSame(['one two', 'three'], Str::wrap('one two three', 7));
        self::assertSame(['one two three'], Str::wrap('one two three', 13), 'exactly the width stays one line');
        self::assertSame(['a', 'b'], Str::wrap("a\nb", 10), 'a newline is a paragraph break');
        self::assertSame(['', 'a'], Str::wrap("\na", 10), 'an empty paragraph keeps its line');
    }

    public function testWrapHardBreaksAWordTooLongToFit(): void
    {
        self::assertSame(['abcd', 'efgh', 'ij'], Str::wrap('abcdefghij', 4));
        self::assertSame(['ab', 'cd', 'x'], Str::wrap('abcd x', 2));
    }

    public function testWrapWithNoRoomReturnsTheInputWhole(): void
    {
        self::assertSame(['anything'], Str::wrap('anything', 0));
        self::assertSame(['anything'], Str::wrap('anything', -3));
    }

    public function testSubstrCountsCharacters(): void
    {
        self::assertSame('本語', Str::substr('日本語', 1));
        self::assertSame('本', Str::substr('日本語', 1, 1));
    }

    /**
     * Width is memoized, and the memo has a ceiling it must survive.
     *
     * ⚠ The control for the cap in {@see Str::width()}: past it, new
     * strings stop being remembered. Correctness must not stop with
     * them, so this fills the memo with throwaway strings and then asks
     * for widths the tables above already pin — a cap that returned a
     * neighbour's answer, or zero, would fail here rather than as a
     * column that drifts one place on a wide terminal.
     */
    public function testWidthStaysCorrectOnceTheMemoIsFull(): void
    {
        for ($i = 0; $i < 4000; $i++) {
            Str::width('w' . $i);
        }

        self::assertSame(1, Str::width('a'));
        self::assertSame(2, Str::width('世'), 'a wide character is still two columns');
        self::assertSame(0, Str::width("\e[31m"), 'and an escape sequence still none');
        self::assertSame(3, Str::width("a世"), 'mixed, measured the same way');

        // Asked twice, answered the same: a memo that returned its key
        // rather than its value would pass the first call only.
        self::assertSame(2, Str::width('世'));
    }

    /** The width these tables say a codepoint has. */
    private function expected(int $code): int
    {
        foreach (self::ZERO as [$low, $high]) {
            if ($code >= $low && $code <= $high) {
                return 0;
            }
        }

        foreach (self::WIDE as [$low, $high]) {
            if ($code >= $low && $code <= $high) {
                return 2;
            }
        }

        return 1;
    }

    /**
     * No false-guard on mb_chr(): every probe above is a real codepoint,
     * and none lands on a surrogate. A range edited to one would fail
     * here loudly, which is the right outcome.
     */
    private function assertWidthAt(int $code, int $width): void
    {
        self::assertSame($width, Str::charWidth(mb_chr($code)), sprintf('U+%04X', $code));
    }
}
