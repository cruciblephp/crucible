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
use LucianoPereira\Crucible\Reporting\Document\Blocks\{Badge, BulletList, FoldingTree, Heading, Image, PageBreak, Paragraph, ProblemList, ProportionBar, RankedList, Svg, TileGrid, Toc};
use LucianoPereira\Crucible\Reporting\Document\SampleDocument;
use LucianoPereira\Crucible\Reporting\ReportFormat\Formats\{MarkdownReportFormat, PdfReportFormat};
use LucianoPereira\Crucible\Reporting\ReportFormat\ReportContext;

use function array_count_values;
use function array_map;

/**
 * `SampleDocument` is what `crucible extensions:preview` renders — the
 * live, code-driven template report. These tests are the real
 * regression guard: every block type must actually render through
 * every format that claims to support the full Document vocabulary,
 * not just exist.
 */
#[CoversClass(SampleDocument::class)]
final class SampleDocumentTest extends TestCase
{
    public function testBuildIncludesEveryKnownBlockTypeExactlyOnce(): void
    {
        $document = SampleDocument::build();

        $classes = array_map(static fn($block): string => $block::class, $document->blocks);

        foreach ([Badge::class, BulletList::class, FoldingTree::class, Heading::class, Image::class, PageBreak::class, Paragraph::class, ProblemList::class, ProportionBar::class, RankedList::class, Svg::class, TileGrid::class, Toc::class] as $expected) {
            $this->assertSame(1, array_count_values($classes)[$expected] ?? 0, "expected exactly one {$expected}");
        }

        $this->assertCount(13, $document->blocks);
    }

    public function testTocIsTheFirstBlock(): void
    {
        $document = SampleDocument::build();

        $this->assertInstanceOf(Toc::class, $document->blocks[0]);
    }

    public function testRendersThroughPdfWithoutThrowing(): void
    {
        $output = (new PdfReportFormat())->render(
            SampleDocument::build(),
            new ReportContext('Sample', 'Author', 'Producer', null, 0.0),
            [],
        );

        $this->assertStringStartsWith("%PDF-1.4\n", $output);
    }

    public function testRendersThroughMarkdownWithoutThrowing(): void
    {
        $output = (new MarkdownReportFormat())->render(
            SampleDocument::build(),
            new ReportContext('Sample', 'Author', 'Producer', null, 0.0),
            [],
        );

        $this->assertStringContainsString('Sample Heading', $output);
    }
}
