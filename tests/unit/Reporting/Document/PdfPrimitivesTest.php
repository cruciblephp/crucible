<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\PdfPrimitives;

use function str_repeat;

/**
 * The layout arithmetic behind every PDF report.
 *
 * ⚠ 831 lines with NO test until 2026-09-20, and 83 escaped mutants —
 * second only to PdfRenderer. Every number here is a cursor advance, a
 * baseline offset or a glyph-width sum, so `arithmetic` mutants had
 * nothing to disagree with.
 *
 * ✓ Every expectation below was computed from the published constants
 * (A4 595.28 x 841.89, 56pt margin, the AFM widths) BEFORE being compared
 * with the output. Rendering first and pasting the result would pin
 * whatever the code does, correct or not.
 */
#[CoversClass(PdfPrimitives::class)]
final class PdfPrimitivesTest extends TestCase
{
    /** 841.89 - 56.0: the cursor starts one margin below the page top. */
    private const float TOP = 785.89;

    /** 595.28 - 2 * 56.0 */
    private const float COLUMNS = 483.28;

    public function testTheCursorStartsOneMarginBelowThePageTop(): void
    {
        self::assertSame(self::TOP, (new PdfPrimitives())->y);
    }

    /**
     * A heading is a bold line, a hairline under it, and one advance.
     *
     * 12pt at 1.4 leading plus 8pt of space is 24.8; the baseline sits
     * 12 below the cursor and the rule 16.5 below it, spanning the full
     * column width.
     */
    public function testAHeadingPlacesItsBaselineRuleAndAdvanceExactly(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->heading('Report');

        self::assertSame(
            "BT 0.00 0.00 0.00 rg /F2 12.00 Tf 56.00 773.89 Td (Report) Tj ET\n"
            . "0.5 w 0.75 G 56.00 769.39 m 539.28 769.39 l S\n",
            $pdf->ops,
        );

        self::assertSame(self::TOP - 24.8, $pdf->y, '12pt at 1.4 leading plus 8pt');
        self::assertSame(56.0 + self::COLUMNS, 539.28, 'the rule spans the column');
    }

    /** A line sits its own size below the cursor and advances 1.4x. */
    public function testALineAdvancesByItsLeading(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->line(PdfPrimitives::HELVETICA, 10.0, 'x');

        self::assertSame(
            "BT 0.00 0.00 0.00 rg /F1 10.00 Tf 56.00 775.89 Td (x) Tj ET\n",
            $pdf->ops,
        );
        self::assertSame(self::TOP - 14.0, $pdf->y);
    }

    public function testAnIndentMovesTheLineRightWithoutChangingTheAdvance(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->line(PdfPrimitives::HELVETICA, 10.0, 'x', null, 20.0);

        self::assertStringContainsString('76.00 775.89 Td', $pdf->ops, 'margin plus indent');
        self::assertSame(self::TOP - 14.0, $pdf->y, 'the indent is horizontal only');
    }

    /**
     * The page breaks when the requested height would cross the footer.
     *
     * The floor is margin + 20, so from the top of a fresh page anything
     * leaving less than 76.0 breaks and anything leaving exactly 76.0
     * does not. One point either side of that is the whole rule.
     */
    public function testEnsureBreaksExactlyAtTheFooterReserve(): void
    {
        $fits = new PdfPrimitives();

        self::assertFalse($fits->ensure(self::TOP - 76.0), 'leaving exactly the reserve fits');
        self::assertSame(self::TOP, $fits->y, 'and the cursor did not move');
        self::assertSame([], $fits->pages);

        $breaks = new PdfPrimitives();
        $breaks->line(PdfPrimitives::HELVETICA, 10.0, 'first page');

        self::assertTrue($breaks->ensure(self::TOP - 75.99), 'one point past the reserve breaks');
        self::assertSame(self::TOP, $breaks->y, 'the cursor resets to the top');
        self::assertCount(1, $breaks->pages, 'and the old page is banked');
        self::assertStringContainsString('(first page)', $breaks->pages[0]);
        self::assertSame('', $breaks->ops, 'the new page starts empty');
    }

    /**
     * Glyph widths are the AFM metrics, summed and scaled by size.
     *
     * '.' is 278 thousandths and 'A' is 667, so at 8pt and 10pt those are
     * 2.224 and 6.67. Courier is fixed-pitch at 600, so three characters
     * at 10pt are exactly 18.
     */
    public function testMeasureSumsTheAfmWidthsAndScalesBySize(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame(2.224, $pdf->measure('.', PdfPrimitives::HELVETICA, 8.0));
        self::assertSame(6.67, $pdf->measure('A', PdfPrimitives::HELVETICA, 10.0));
        self::assertSame(18.0, $pdf->measure('abc', PdfPrimitives::COURIER, 10.0));
        self::assertSame(0.0, $pdf->measure('', PdfPrimitives::HELVETICA, 10.0));
    }

    /** Bold carries its own table: 'A' is 722 there against Helvetica's 667. */
    public function testBoldUsesItsOwnWidthTable(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame(7.22, $pdf->measure('A', PdfPrimitives::BOLD, 10.0));
        self::assertSame(6.67, $pdf->measure('A', PdfPrimitives::HELVETICA, 10.0));
    }

    /**
     * A leader fills the gap with dots, 8pt in, in grey.
     *
     * The dot is 2.224 wide at 8pt and 12pt of the span is reserved, so
     * 100 to 200 fits floor(88 / 2.224) = 39 of them.
     */
    public function testALeaderFillsTheGapWithMeasuredDots(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->leader(100.0, 200.0, 500.0);

        self::assertSame(
            'BT 0.46 0.46 0.46 rg /F1 8.00 Tf 108.00 500.00 Td (' . str_repeat('.', 39) . ") Tj ET\n",
            $pdf->ops,
        );
    }

    /**
     * Too narrow to read as a leader means none at all.
     *
     * The guard is "more than two dots", so a gap that fits two or fewer
     * draws nothing rather than a stray pair.
     */
    public function testANarrowGapGetsNoLeaderAtAll(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->leader(100.0, 110.0, 500.0);

        self::assertSame('', $pdf->ops);

        // Nudged past the boundary rather than sitting on it. ⚠ At exactly
        // 12.0 + 3 * 2.224 the division floors to 2, not 3: 6.672 / 2.224
        // is 2.9999… in binary floating point. Asserting the exact
        // boundary would pin an artefact of the rounding rather than the
        // rule, so this asserts the rule and names the artefact.
        // The whole stream, not a dot count: `0.46`, `8.00` and the
        // coordinates all contain '.' too, so counting the character
        // measures the decimal points as much as the leader.
        $threeDots = new PdfPrimitives();
        $threeDots->leader(100.0, 118.68, 500.0);

        self::assertSame(
            "BT 0.46 0.46 0.46 rg /F1 8.00 Tf 108.00 500.00 Td (...) Tj ET\n",
            $threeDots->ops,
            'three is more than two',
        );

        $twoDots = new PdfPrimitives();
        $twoDots->leader(100.0, 100.0 + 12.0 + 2.5 * 2.224, 500.0);

        self::assertSame('', $twoDots->ops, 'two is not');
    }

    /**
     * A disc is four arcs at 0.5523 of the radius.
     *
     * Note this is NOT the full kappa the SVG renderer uses — 0.5523
     * rather than 0.5522847498 — so r=4 gives 2.2092 and the control
     * points land on 22.21 and 12.21.
     */
    public function testADiscPlacesEveryArcControlPoint(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->disc(10.0, 20.0, 4.0, [1.0, 0.0, 0.0]);

        self::assertSame(
            "1.00 0.00 0.00 rg 14.00 20.00 m "
            . "14.00 22.21 12.21 24.00 10.00 24.00 c "
            . "7.79 24.00 6.00 22.21 6.00 20.00 c "
            . "6.00 17.79 7.79 16.00 10.00 16.00 c "
            . "12.21 16.00 14.00 17.79 14.00 20.00 c f\n",
            $pdf->ops,
        );
    }

    public function testABoxIsAFilledRectangleInItsOwnColour(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->box(1.0, 2.0, 3.0, 4.0, [0.5, 0.25, 0.0]);

        self::assertSame("0.50 0.25 0.00 rg 1.00 2.00 3.00 4.00 re f\n", $pdf->ops);
    }

    /** Two decimals, always — a PDF number is not a float literal. */
    public function testNumbersAreTwoDecimalPlaces(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame('0.00', $pdf->number(0.0));
        self::assertSame('1.00', $pdf->number(1.0));
        self::assertSame('2.22', $pdf->number(2.224));
        self::assertSame('2.23', $pdf->number(2.225), 'and it rounds');
        self::assertSame('-1.50', $pdf->number(-1.5));
    }

    /** The three characters that would otherwise end a literal string. */
    public function testEscapeProtectsTheStringDelimiters(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame('\\\\', $pdf->escape('\\'));
        self::assertSame('\\(', $pdf->escape('('));
        self::assertSame('\\)', $pdf->escape(')'));
        self::assertSame('a\\(b\\)c', $pdf->escape('a(b)c'));
        self::assertSame('plain', $pdf->escape('plain'));
    }

    /**
     * Courier is fixed-pitch, so a width is a column count.
     *
     * At 10pt each glyph is 6.0 wide, so 60 points is exactly 10 columns
     * and the chunking must not be off by one in either direction.
     */
    public function testWrapChunksAtTheFixedPitchColumnBudget(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame(['abcdefghij', 'klm'], $pdf->wrap('abcdefghijklm', 10.0, 60.0));
        self::assertSame(['abcdefghij'], $pdf->wrap('abcdefghij', 10.0, 60.0), 'exactly full is one row');
    }

    public function testWrapKeepsNewlinesAsRowsAndNormalisesCrlf(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame(['a', 'b'], $pdf->wrap("a\nb", 10.0, 60.0));
        self::assertSame(['a', 'b'], $pdf->wrap("a\r\nb", 10.0, 60.0));
        self::assertSame(['a', '', 'b'], $pdf->wrap("a\n\nb", 10.0, 60.0), 'a blank line is a row');
        self::assertSame([''], $pdf->wrap('', 10.0, 60.0));
    }

    /** A width too small for one glyph still yields one column, not zero. */
    public function testWrapNeverDropsToZeroColumns(): void
    {
        $pdf = new PdfPrimitives();

        self::assertSame(['a', 'b', 'c'], $pdf->wrap('abc', 10.0, 1.0));
    }

    /** Bookmarks record the page being built when the heading is drawn. */
    public function testBookmarksCarryTheirLevelTitleAndPage(): void
    {
        $pdf = new PdfPrimitives();
        $pdf->bookmark(1, 'First');
        $pdf->ensure(self::TOP);
        $pdf->bookmark(2, 'Second');

        self::assertSame(
            [
                ['level' => 1, 'title' => 'First', 'pageIndex' => 0],
                ['level' => 2, 'title' => 'Second', 'pageIndex' => 1],
            ],
            $pdf->bookmarkEntries(),
        );
    }
}
