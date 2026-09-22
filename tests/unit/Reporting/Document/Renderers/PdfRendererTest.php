<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Renderers;

use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Heading;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Image;
use LucianoPereira\Crucible\Reporting\Document\Blocks\PageBreak;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Paragraph;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Svg;
use LucianoPereira\Crucible\Reporting\Document\Blocks\TileGrid;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Toc;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\Inline\Text;
use LucianoPereira\Crucible\Reporting\Document\Renderers\PdfRenderer;
use LucianoPereira\Crucible\Reporting\Document\TileEntry;

use function base64_decode;
use function preg_match_all;
use function str_contains;
use function substr_count;

/**
 * A 4x3 solid-red baseline JPEG (693 bytes, 3 channels, no Adobe
 * APP14 marker) — small enough to inline, real enough that
 * `getimagesizefromstring()` reports genuine width/height/channels.
 */
#[CoversClass(PdfRenderer::class)]
final class PdfRendererTest extends TestCase
{
    private const string FIXTURE_JPEG = '/9j/4AAQSkZJRgABAQEAYABgAAD//gA7Q1JFQVRPUjogZ2QtanBlZyB2MS4wICh1c2luZyBJSkcgSlBFRyB2NjIpLCBxdWFsaXR5ID0gOTAK/9sAQwADAgIDAgIDAwMDBAMDBAUIBQUEBAUKBwcGCAwKDAwLCgsLDQ4SEA0OEQ4LCxAWEBETFBUVFQwPFxgWFBgSFBUU/9sAQwEDBAQFBAUJBQUJFA0LDRQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQU/8AAEQgAAwAEAwERAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/aAAwDAQACEQMRAD8A8Cr8yP7jP//Z';

    public function testEmbedsTheJpegAsAnXObjectWithDctDecodeAndTheJpegsOwnDimensions(): void
    {
        $output = $this->render([new Image($this->jpeg(), 40.0)]);

        $this->assertStringContainsString('/Subtype /Image', $output);
        $this->assertStringContainsString('/Filter /DCTDecode', $output);
        $this->assertStringContainsString('/Width 4', $output);
        $this->assertStringContainsString('/Height 3', $output);
        $this->assertStringContainsString('/ColorSpace /DeviceRGB', $output);
    }

    public function testThePageThatDrawsTheImageListsItInItsResourcesAndPaintsIt(): void
    {
        $output = $this->render([new Image($this->jpeg(), 40.0)]);

        $this->assertStringContainsString('/XObject << /Im1 ', $output);
        $this->assertStringContainsString('/Im1 Do Q', $output);
    }

    public function testTheSameBytesDrawnTwiceEmbedOnlyOnce(): void
    {
        $output = $this->render([new Image($this->jpeg(), 40.0), new Image($this->jpeg(), 40.0)]);

        $this->assertSame(1, substr_count($output, '/Subtype /Image'));
    }

    public function testNonJpegBytesAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->render([new Image('not a jpeg', 40.0)]);
    }

    public function testPageBreakStartsAFreshPage(): void
    {
        $output = $this->render([
            new Image($this->jpeg(), 40.0),
            new PageBreak(),
            new Heading(1, [new Text('Chapter One')]),
        ]);

        $this->assertSame(2, substr_count($output, '/Type /Page /Parent'));
    }

    public function testHeadingsProduceOutlineBookmarks(): void
    {
        $output = $this->render([
            new Heading(1, [new Text('Chapter One')]),
            new Paragraph([new Text('Body text.')]),
            new Heading(2, [new Text('Section A')]),
        ]);

        $this->assertStringContainsString('/Type /Outlines', $output);
        $this->assertStringContainsString('/Outlines ', $output); // Catalog references the root
        $this->assertStringContainsString('/Title (Chapter One)', $output);
        $this->assertStringContainsString('/Title (Section A)', $output);
        $this->assertStringContainsString('/Dest [', $output);
    }

    public function testNoHeadingsProducesNoOutlines(): void
    {
        $output = $this->render([new Paragraph([new Text('Just a paragraph.')])]);

        $this->assertStringNotContainsString('/Type /Outlines', $output);
    }

    public function testTocBlockRendersATableOfContentsPageWithShiftedPageNumbers(): void
    {
        $output = $this->render([
            new Toc(),
            new Heading(1, [new Text('Chapter One')]),
            new Paragraph([new Text('First chapter body.')]),
            new PageBreak(),
            new Heading(1, [new Text('Chapter Two')]),
        ]);

        $this->assertStringContainsString('(Table of Contents)', $output);
        $this->assertStringContainsString('(Chapter One)', $output);
        $this->assertStringContainsString('(Chapter Two)', $output);

        // TOC gets its own dedicated page, so the body's first chapter
        // starts on page 2 and the PageBreak'd second chapter on page 3.
        $this->assertSame(3, substr_count($output, '/Type /Page /Parent'));
        $this->assertStringContainsString('/Dest [9 0 R', $output); // Chapter One's page object
        $this->assertStringContainsString('/Dest [11 0 R', $output); // Chapter Two's page object
    }

    public function testTocIsIgnoredUnlessItIsTheFirstBlock(): void
    {
        $output = $this->render([
            new Heading(1, [new Text('Chapter One')]),
            new Toc(),
        ]);

        $this->assertStringNotContainsString('Table of Contents', $output);
        $this->assertSame(1, substr_count($output, '/Type /Page /Parent'));
    }

    public function testSvgDrawsShapesFitToWidthPreservingAspectRatio(): void
    {
        $svg = '<svg viewBox="0 0 100 50"><rect width="100" height="50" fill="#ff0000"/></svg>';

        $output = $this->render([new Svg($svg, 40.0)]);

        // width=40 over a 100-wide viewBox scales x by 0.4; height (aspect-
        // preserved from 50/100) is 20, so the same 0.4 scales y too (negated
        // for the SVG-y-down / PDF-y-up flip). The outer cm uses
        // PdfPrimitives::number()'s 2-decimal format, unlike the 4-decimal
        // shape operators SvgDocument emits inside it.
        $this->assertStringContainsString('0.40 0.00 0.00 -0.40', $output);
        $this->assertStringContainsString('1.0000 0.0000 0.0000 rg', $output);
        $this->assertStringContainsString(' re', $output);
    }

    public function testMalformedSvgMarkupIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->render([new Svg('not an svg', 40.0)]);
    }

    public function testSvgWithAnUnsupportedFeatureIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->render([new Svg('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="url(#g)"/></svg>', 40.0)]);
    }

    /**
     * @param list<\LucianoPereira\Crucible\Reporting\Document\Block> $blocks
     */
    /**
     * Every tile coordinate, on a grid whose maths had none asserted.
     *
     * ⚠ 133 escaped mutants in this renderer, the most of any file, and
     * the block renderers are nothing but arithmetic: a cell is
     * (columns - 2*gutter)/3, a slot's x is margin + slot*(cell+gutter),
     * a span's width is span*cell + (span-1)*gutter. The old tests
     * asserted PDF object structure — /Subtype /Image, /Width 4 — which
     * no coordinate mutation touches.
     *
     * ✓ Computed from the constants first: columns 483.28, gutter 12,
     * so cell is 153.0933 and the three slots land on 56.00, 221.09 and
     * 386.19. Labels sit 9 in, baselines 9.5 below the cursor.
     */
    public function testTheTileGridPlacesEveryColumnLabelAndStat(): void
    {
        $stream = $this->contentStreamContaining('(A)', [new TileGrid([
            new TileEntry('tests/A.php', 7, 0.0, 0),
            new TileEntry('tests/B.php', 3, 0.0, 2),
            new TileEntry('tests/C.php', 5, 0.0, 4),
        ])]);

        // Three slots, three label origins, one baseline.
        self::assertStringContainsString('/F1 9.00 Tf 65.00 776.39 Td (A) Tj', $stream, 'slot 0 at margin + 9');
        self::assertStringContainsString('/F1 9.00 Tf 230.09 776.39 Td (B) Tj', $stream, 'slot 1 at 221.09 + 9');
        self::assertStringContainsString('/F1 9.00 Tf 395.19 776.39 Td (C) Tj', $stream, 'slot 2 at 386.19 + 9');

        // The stat is right-aligned inside its own cell: x + width minus
        // its measured width, which for '7' at 8pt is 4.448.
        self::assertStringContainsString('/F1 8.00 Tf 204.65 776.39 Td (7) Tj', $stream);
    }

    /**
     * Rank decides the dot, and which colour it is.
     *
     * Rank 0 draws none at all; 3 and above is red, below it amber. The
     * dot sits 2.5 right of the cell and 3.0 above the baseline at r=2.2,
     * so its first arc point is at x + 2.5 + 2.2.
     */
    public function testATilesRankDecidesItsDotAndColour(): void
    {
        $stream = $this->contentStreamContaining('(A)', [new TileGrid([
            new TileEntry('tests/A.php', 1, 0.0, 0),
            new TileEntry('tests/B.php', 1, 0.0, 2),
            new TileEntry('tests/C.php', 1, 0.0, 4),
        ])]);

        self::assertStringContainsString('0.70 0.42 0.00 rg 225.79 779.39 m', $stream, 'rank 2 is amber');
        self::assertStringContainsString('0.78 0.16 0.16 rg 390.89 779.39 m', $stream, 'rank 4 is red');
        self::assertStringNotContainsString('rg 60.70 779.39 m', $stream, 'rank 0 draws no dot');
        self::assertSame(2, substr_count($stream, ' 779.39 m '), 'two dots for three tiles');
    }

    /**
     * The table of contents indents by level and right-aligns its pages.
     *
     * Level 1 is bold at 11pt with no indent; level 2 is Helvetica at
     * 10pt indented 14. The title advances 15 * 1.4 + 8, each entry its
     * own size * 1.4 — so the baselines are 770.89, 745.89 and 731.49.
     */
    public function testTheTableOfContentsIndentsAndSizesByLevel(): void
    {
        $stream = $this->contentStreamContaining('(Table of Contents)', [
            new Toc(),
            new Heading(1, [new Text('One')]),
            new Heading(2, [new Text('Two')]),
        ]);

        self::assertStringContainsString('/F2 15.00 Tf 56.00 770.89 Td (Table of Contents) Tj', $stream);
        self::assertStringContainsString('/F2 11.00 Tf 56.00 745.89 Td (One) Tj', $stream, 'level 1: bold, 11pt, flush');
        self::assertStringContainsString('/F1 10.00 Tf 70.00 731.49 Td (Two) Tj', $stream, 'level 2: 10pt, indented 14');
    }

    /** A page number is right-aligned by its own measured width. */
    public function testTocPageNumbersAreRightAlignedInTheColumn(): void
    {
        $stream = $this->contentStreamContaining('(Table of Contents)', [
            new Toc(),
            new Heading(1, [new Text('One')]),
        ]);

        // 56 + 483.28 - measure('2', BOLD, 11) = 539.28 - 6.116
        self::assertStringContainsString('/F2 11.00 Tf 533.16 745.89 Td (2) Tj', $stream);
    }

    /**
     * The first content stream that mentions $needle.
     *
     * A rendered PDF is objects and streams; the geometry lives in one of
     * them, and finding it by content keeps these tests independent of
     * object numbering.
     *
     * @param list<Block> $blocks
     */
    private function contentStreamContaining(string $needle, array $blocks): string
    {
        $output = $this->render($blocks);

        self::assertNotSame(0, preg_match_all('/stream\n(.*?)\nendstream/s', $output, $matches), 'the PDF has streams');

        foreach ($matches[1] as $stream) {
            if (str_contains($stream, $needle)) {
                return $stream;
            }
        }

        self::fail('No content stream contains ' . $needle);
    }

    /** @param list<Block> $blocks */
    private function render(array $blocks): string
    {
        return (new PdfRenderer())->render(new Document($blocks), 'Title', 'Author', 'Producer', null, 0.0);
    }

    private function jpeg(): string
    {
        return (string) base64_decode(self::FIXTURE_JPEG, true);
    }
}
