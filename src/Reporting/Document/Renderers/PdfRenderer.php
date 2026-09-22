<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Renderers;

use DateTimeImmutable;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Badge;
use LucianoPereira\Crucible\Reporting\Document\Blocks\BulletList;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Heading;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Image;
use LucianoPereira\Crucible\Reporting\Document\Blocks\PageBreak;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Paragraph;
use LucianoPereira\Crucible\Reporting\Document\Blocks\ProblemList;
use LucianoPereira\Crucible\Reporting\Document\Blocks\ProportionBar;
use LucianoPereira\Crucible\Reporting\Document\Blocks\RankedList;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Svg;
use LucianoPereira\Crucible\Reporting\Document\Blocks\TileGrid;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Toc;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\FoldingNode;
use LucianoPereira\Crucible\Reporting\Document\Inline\Code;
use LucianoPereira\Crucible\Reporting\Document\Inline\Strong;
use LucianoPereira\Crucible\Reporting\Document\Inline\Text;
use LucianoPereira\Crucible\Reporting\Document\PdfPrimitives;
use LucianoPereira\Crucible\Reporting\Document\Run;
use LucianoPereira\Crucible\Reporting\Document\Tone;
use LucianoPereira\Crucible\Reporting\PrettyName;
use UnexpectedValueException;

use function array_map;
use function array_values;
use function count;
use function implode;
use function sprintf;

/**
 * Walks a `Document` to PDF 1.4 bytes via a composed `PdfPrimitives`
 * — the same primitives-class-plus-renderer split as the lrv pattern
 * this is modeled on (D-091's addendum), one method per block type.
 * Zero dependencies: base-14 fonts only, no ext-zlib.
 */
final class PdfRenderer
{
    private const array GREEN = [0.18, 0.49, 0.20];
    private const array AMBER = [0.70, 0.42, 0.0];
    private const array RED   = [0.78, 0.16, 0.16];
    private const array GRAY  = [0.46, 0.46, 0.46];
    private const array WHITE = [1.0, 1.0, 1.0];

    private PdfPrimitives $pdf;

    /** @var array<string, bool> files already shown on a RankedList — their tiles never repeat the time */
    private array $spotlight = [];

    private float $runtime = 0.0;

    public function render(
        Document $document,
        string $title,
        string $author,
        string $producer,
        ?DateTimeImmutable $createdAt,
        float $runtime,
    ): string {
        $this->runtime   = $runtime;
        $this->spotlight = [];

        foreach ($document->blocks as $block) {
            if ($block instanceof RankedList) {
                foreach ($block->entries as $entry) {
                    $this->spotlight[$entry->file] = true;
                }
            }
        }

        $hasToc       = ($document->blocks[0] ?? null) instanceof Toc;
        $tocEntries   = [];
        $tocPageCount = 0;

        if ($hasToc) {
            // Pass 1 (dry run): render the body alone — its own bookmark()
            // calls collect (level, title, pageIndex) for every heading,
            // as if there were no TOC in front. Output is discarded.
            $this->pdf = new PdfPrimitives();

            foreach ($document->blocks as $block) {
                if (!$block instanceof Toc) {
                    $this->block($block);
                }
            }

            $tocEntries = $this->pdf->bookmarkEntries();

            // Self-size the TOC by really laying it out once on a
            // throwaway primitives instance — reuses the exact same
            // line-wrap/page-break logic pass 2 will use for real,
            // rather than a separate estimate that could drift from it.
            // The trailing forced break gives the TOC its own dedicated
            // page(s), so chapter one always starts fresh — the same
            // ensure(HEIGHT) a PageBreak block itself uses.
            $sizer     = new PdfPrimitives();
            $this->pdf = $sizer;
            $this->renderTocPages($tocEntries, 0);
            $this->pdf->ensure(PdfPrimitives::HEIGHT);
            $tocPageCount = count($sizer->pages);
        }

        // Pass 2 (real render): TOC page(s) first, with page numbers
        // shifted by $tocPageCount, then the body — its true page
        // numbers and bookmark /Dest targets come out correct since the
        // TOC now genuinely precedes it.
        $this->pdf = new PdfPrimitives();

        if ($hasToc) {
            $this->renderTocPages($tocEntries, $tocPageCount);
            $this->pdf->ensure(PdfPrimitives::HEIGHT);
        }

        foreach ($document->blocks as $block) {
            if (!$block instanceof Toc) {
                $this->block($block);
            }
        }

        return $this->pdf->document($title, $author, $producer, $createdAt);
    }

    /**
     * @param list<array{level: int, title: string, pageIndex: int}> $entries
     */
    private function renderTocPages(array $entries, int $pageShift): void
    {
        $this->pdf->ensure(15.0 * 1.4);
        $this->pdf->text(PdfPrimitives::MARGIN, $this->pdf->y - 15.0, PdfPrimitives::BOLD, 15.0, $this->pdf->encode('Table of Contents'));
        $this->pdf->y -= 15.0 * 1.4 + 8.0;

        $columns = $this->WIDTH() - 2 * PdfPrimitives::MARGIN;

        foreach ($entries as $entry) {
            $indent = ($entry['level'] - 1) * 14.0;
            $font   = $entry['level'] === 1 ? PdfPrimitives::BOLD : PdfPrimitives::HELVETICA;
            $size   = $entry['level'] === 1 ? 11.0 : 10.0;

            $this->pdf->ensure($size * 1.4);
            $baseline = $this->pdf->y - $size;
            $title    = $this->pdf->encode($entry['title']);
            $pageText = $this->pdf->encode((string) ($pageShift + $entry['pageIndex'] + 1));
            $pageX    = PdfPrimitives::MARGIN + $columns - $this->pdf->measure($pageText, $font, $size);

            $this->pdf->text(PdfPrimitives::MARGIN + $indent, $baseline, $font, $size, $title);
            $this->pdf->text($pageX, $baseline, $font, $size, $pageText);
            $this->pdf->leader(PdfPrimitives::MARGIN + $indent + $this->pdf->measure($title, $font, $size), $pageX, $baseline);

            $this->pdf->y -= $size * 1.4;
        }
    }

    /** @phpcpd-ignore-clone Dispatch table — one arm per block type, one table per output format by design. */
    private function block(Block $block): void
    {
        match (true) {
            $block instanceof Heading       => $this->heading($block),
            $block instanceof Image         => $this->image($block),
            $block instanceof Svg           => $this->svg($block),
            $block instanceof PageBreak     => $this->pdf->ensure(PdfPrimitives::HEIGHT),
            $block instanceof Paragraph     => $this->paragraph($block),
            $block instanceof BulletList    => $this->bulletList($block),
            $block instanceof Badge         => $this->badge($block),
            $block instanceof ProblemList   => $this->problemList($block),
            $block instanceof ProportionBar => $this->proportionBar($block),
            $block instanceof RankedList    => $this->rankedList($block),
            $block instanceof FoldingTree   => $this->foldingTree($block),
            $block instanceof TileGrid      => $this->tileGrid($block, $this->WIDTH() - 2 * PdfPrimitives::MARGIN),
            default                         => throw new UnexpectedValueException('Unknown block type: ' . $block::class),
        };
    }

    private function WIDTH(): float
    {
        return PdfPrimitives::WIDTH;
    }

    private function heading(Heading $heading): void
    {
        $text = $this->runText($heading->runs);
        $this->pdf->bookmark($heading->level, $text);

        if ($heading->level === 1) {
            $this->pdf->ensure(15.0 * 1.4);
            $this->pdf->text(PdfPrimitives::MARGIN, $this->pdf->y - 15.0, PdfPrimitives::BOLD, 15.0, $this->pdf->encode($text));
            $this->pdf->y -= 15.0 * 1.4;

            return;
        }

        $this->pdf->heading($text);
    }

    private function paragraph(Paragraph $paragraph): void
    {
        $this->pdf->line(PdfPrimitives::HELVETICA, 10.5, $this->runText($paragraph->runs));
    }

    /**
     * Fit-to-width unless the block narrows it; height always follows
     * the JPEG's own aspect ratio, never distorted.
     */
    private function image(Image $image): void
    {
        $width                          = $image->width ?? $this->WIDTH() - 2 * PdfPrimitives::MARGIN;
        [$naturalWidth, $naturalHeight] = $this->pdf->imageSize($image->jpegBytes);
        $height                         = $naturalHeight * ($width / $naturalWidth);

        $this->pdf->ensure($height);
        $this->pdf->image($image->jpegBytes, PdfPrimitives::MARGIN, $this->pdf->y - $height, $width, $height);
        $this->pdf->y -= $height + 8.0;
    }

    /**
     * Fit-to-width unless the block narrows it; height always follows
     * the SVG's own viewBox aspect ratio, never distorted.
     */
    private function svg(Svg $svg): void
    {
        $width                          = $svg->width ?? $this->WIDTH() - 2 * PdfPrimitives::MARGIN;
        [$naturalWidth, $naturalHeight] = $this->pdf->svgSize($svg->markup);
        $height                         = $naturalHeight * ($width / $naturalWidth);

        $this->pdf->ensure($height);
        $this->pdf->svg($svg->markup, PdfPrimitives::MARGIN, $this->pdf->y - $height, $width, $height);
        $this->pdf->y -= $height + 8.0;
    }

    private function bulletList(BulletList $list): void
    {
        foreach ($list->items as $item) {
            $this->pdf->line(PdfPrimitives::HELVETICA, 9.5, '• ' . $this->runText($item), null, 10.0);
        }

        $this->pdf->y -= 4.0;
    }

    private function badge(Badge $badge): void
    {
        $tone = $this->tone($badge->tone);

        $this->pdf->ensure(20.0 + 8.0);
        $width = $this->pdf->measure($this->pdf->encode($badge->text), PdfPrimitives::BOLD, 11.0) + 16.0;
        $this->pdf->box(PdfPrimitives::MARGIN, $this->pdf->y - 20.0, $width, 20.0, $tone);
        $this->pdf->text(PdfPrimitives::MARGIN + 8.0, $this->pdf->y - 14.5, PdfPrimitives::BOLD, 11.0, $this->pdf->encode($badge->text), self::WHITE);
        $this->pdf->y -= 20.0 + 8.0;
    }

    private function proportionBar(ProportionBar $bar): void
    {
        $total = 0;

        foreach ($bar->shares as $share) {
            $total += $share->count;
        }

        if ($total === 0) {
            return;
        }

        $columns = $this->WIDTH() - 2 * PdfPrimitives::MARGIN;
        $this->pdf->ensure(5.0 + 14.0);
        $x = PdfPrimitives::MARGIN;

        foreach ($bar->shares as $share) {
            if ($share->count === 0) {
                continue;
            }

            $width = $columns * $share->count / $total;
            $this->pdf->box($x, $this->pdf->y - 5.0, $width, 5.0, $this->tone($share->tone));
            $x += $width;
        }

        $this->pdf->y -= 5.0 + 14.0;
    }

    private function problemList(ProblemList $list): void
    {
        $columns = $this->WIDTH() - 2 * PdfPrimitives::MARGIN;
        $tone    = $list->tone instanceof \LucianoPereira\Crucible\Reporting\Document\Tone ? $this->tone($list->tone) : self::GRAY;

        foreach ($list->entries as $index => $entry) {
            $lines = $this->pdf->flow($this->pdf->encode($entry->testName . ' [' . $entry->outcome . ']'), $columns - 20.0, PdfPrimitives::HELVETICA, 9.5);

            $this->pdf->ensure(count($lines) * 13.0 + 11.0);
            $top = $this->pdf->y;

            $tag = $this->pdf->encode((string) ($index + 1));
            $this->pdf->text(PdfPrimitives::MARGIN + 14.0 - $this->pdf->measure($tag, PdfPrimitives::HELVETICA, 8.5), $top - 9.5, PdfPrimitives::HELVETICA, 8.5, $tag, self::GRAY);

            foreach ($lines as $line) {
                $this->pdf->text(PdfPrimitives::MARGIN + 20.0, $this->pdf->y - 9.5, PdfPrimitives::HELVETICA, 9.5, $line);
                $this->pdf->y -= 13.0;
            }

            if ($entry->quarantined) {
                $this->pdf->text(PdfPrimitives::MARGIN + 20.0, $this->pdf->y + 3.0, PdfPrimitives::HELVETICA, 8.0, $this->pdf->encode('[quarantined]'), self::AMBER);
            }

            if ($entry->detail !== null) {
                foreach ($this->pdf->wrap($entry->detail, 7.5, $columns - 24.0) as $row) {
                    $this->pdf->ensure(7.5 * 1.4);
                    $this->pdf->text(PdfPrimitives::MARGIN + 20.0, $this->pdf->y - 7.5, PdfPrimitives::COURIER, 7.5, $row, self::GRAY);
                    $this->pdf->y -= 7.5 * 1.4;
                }
            }

            $this->pdf->box(PdfPrimitives::MARGIN + 4.0, $this->pdf->y + 3.0, 2.0, $top - $this->pdf->y - 3.0, $tone);
            $this->pdf->y -= 4.0;
        }

        $this->pdf->y -= 4.0;
    }

    private function rankedList(RankedList $list): void
    {
        if ($list->entries === []) {
            return;
        }

        $columns   = $this->WIDTH() - 2 * PdfPrimitives::MARGIN;
        $inSeconds = $list->entries[0]->duration >= 1.0;

        foreach ($list->entries as $index => $entry) {
            $font  = $index === 0 ? PdfPrimitives::BOLD : PdfPrimitives::HELVETICA;
            $shade = $index === 0 ? null : self::GRAY;
            $time  = $this->pdf->encode($inSeconds
                ? sprintf('%.2fs', $entry->duration)
                : sprintf('%.1fms', $entry->duration * 1_000));
            $lines = $this->pdf->flow($this->pdf->encode(sprintf('%s · %s', $entry->testName, PrettyName::ofFile($entry->file))), $columns - 70.0, $font, 9.0);

            $this->pdf->ensure(count($lines) * 13.0);

            foreach ($lines as $i => $line) {
                $baseline = $this->pdf->y - 9.5;
                $this->pdf->text(PdfPrimitives::MARGIN, $baseline, $font, 9.0, $line);

                if ($i === 0) {
                    $timeX = PdfPrimitives::MARGIN + $columns - $this->pdf->measure($time, $font, 9.0);
                    $this->pdf->text($timeX, $baseline, $font, 9.0, $time, $shade);
                    $this->pdf->leader(PdfPrimitives::MARGIN + $this->pdf->measure($line, $font, 9.0), $timeX, $baseline);
                }

                $this->pdf->y -= 13.0;
            }
        }

        $this->pdf->y -= 4.0;
    }

    private function foldingTree(FoldingTree $tree): void
    {
        foreach ($tree->visible as $label => $node) {
            $this->node($label, $node, 0);
        }

        if ($tree->foldedDirectoryCount > 0) {
            $this->pdf->ensure(13.0);
            $this->pdf->text(PdfPrimitives::MARGIN, $this->pdf->y - 9.5, PdfPrimitives::HELVETICA, 9.0, $this->pdf->encode(sprintf(
                '+ %d more %s · %d %s · %.3fs',
                $tree->foldedDirectoryCount,
                $tree->foldedDirectoryCount === 1 ? 'directory' : 'directories',
                $tree->foldedTestCount,
                $tree->foldedTestCount === 1 ? 'test' : 'tests',
                $tree->foldedDuration,
            )), self::GRAY);
            $this->pdf->y -= 13.0;
        }
    }

    private function node(string $label, FoldingNode $node, int $depth): void
    {
        $indent = $depth * 14.0;

        if ($node->children === [] && count($node->files) === 1) {
            foreach ($node->files as $file => $tests) {
                $this->inline($label, $file, $tests, $indent);
            }

            return;
        }

        if ($node->files === [] && FoldingNode::onlyInlinable($node->children)) {
            foreach ($node->children as $childLabel => $child) {
                foreach ($child->files as $file => $tests) {
                    $this->inline($label . '/' . $childLabel, $file, $tests, $indent);
                }
            }

            return;
        }

        $own  = $node->ownPassedCount();
        $meta = $own > 0
            ? sprintf('· %d %s · %.3fs', $own, $own === 1 ? 'test' : 'tests', $node->time)
            : sprintf('· %.3fs', $node->time);

        $this->pdf->ensure(10.0 * 1.4 + 13.0);
        $bold = $this->pdf->encode($label);
        $this->pdf->text(PdfPrimitives::MARGIN + $indent, $this->pdf->y - 10.0, PdfPrimitives::BOLD, 10.0, $bold);
        $this->pdf->text(PdfPrimitives::MARGIN + $indent + $this->pdf->measure($bold, PdfPrimitives::BOLD, 10.0) + 5.0, $this->pdf->y - 10.0, PdfPrimitives::HELVETICA, 8.5, $this->pdf->encode($meta), self::GRAY);
        $this->pdf->y -= 10.0 * 1.4 + 2.0;

        if ($node->files !== []) {
            $this->tileGrid(TileGrid::build($node->files), $this->WIDTH() - 2 * PdfPrimitives::MARGIN - $indent, $indent);
        }

        foreach ($node->children as $childLabel => $child) {
            $this->node($childLabel, $child, $depth + 1);
        }
    }

    /**
     * @param non-empty-string   $file
     * @param list<TestFinished> $tests
     */
    private function inline(string $label, string $file, array $tests, float $indent): void
    {
        $this->pdf->ensure(13.0);

        $baseline = $this->pdf->y - 9.5;
        $bold     = $this->pdf->encode($label);
        $name     = $this->pdf->encode('· ' . PrettyName::ofFile($file));
        $passed   = FoldingNode::passedCountOf($tests);
        $meta     = $this->pdf->encode(sprintf(
            '· %d %s · %.3fs',
            $passed,
            $passed === 1 ? 'test' : 'tests',
            FoldingNode::timeOf($tests),
        ));
        $nameX = PdfPrimitives::MARGIN + $indent + $this->pdf->measure($bold, PdfPrimitives::BOLD, 10.0) + 5.0;
        $rank  = FoldingNode::rankOf($tests);

        $this->pdf->text(PdfPrimitives::MARGIN + $indent, $baseline, PdfPrimitives::BOLD, 10.0, $bold);

        if ($rank > 0) {
            $this->pdf->disc($nameX + 8.5, $baseline + 3.0, 2.2, $rank >= 3 ? self::RED : self::AMBER);
            $nameX += 6.0;
        }

        $this->pdf->text($nameX, $baseline, PdfPrimitives::HELVETICA, 9.0, $name);
        $this->pdf->text($nameX + $this->pdf->measure($name, PdfPrimitives::HELVETICA, 9.0) + 5.0, $baseline, PdfPrimitives::HELVETICA, 8.5, $meta, self::GRAY);

        $this->pdf->y -= 13.0 + 2.0;
    }

    private function tileGrid(TileGrid $grid, float $columns, float $indent = 0.0): void
    {
        $gutter = 12.0;
        $cell   = ($columns - 2.0 * $gutter) / 3.0;

        /** @var list<array{label: string, stat: string, span: int, rank: int}> $queue */
        $queue = [];

        foreach ($grid->tiles as $tile) {
            $stat = (string) $tile->passedCount;

            if ($this->runtime > 0.0 && $tile->duration > 0.02 * $this->runtime && !isset($this->spotlight[$tile->file])) {
                $stat = sprintf('%s · %.3fs', $stat, $tile->duration);
            }

            $stat   = $this->pdf->encode($stat);
            $label  = $this->pdf->encode(PrettyName::ofFile($tile->file));
            $needed = 9.0 + $this->pdf->measure($label, PdfPrimitives::HELVETICA, 9.0) + 8.0 + $this->pdf->measure($stat, PdfPrimitives::HELVETICA, 8.0);

            $queue[] = [
                'label' => $label,
                'stat'  => $stat,
                'span'  => match (true) {
                    $needed <= $cell                 => 1,
                    $needed <= 2.0 * $cell + $gutter => 2,
                    default                          => 3,
                },
                'rank' => $tile->rank,
            ];
        }

        $slot = 0;

        while ($queue !== []) {
            $room = 3 - $slot % 3;
            $pick = null;

            foreach ($queue as $index => $tile) {
                if ($tile['span'] <= $room) {
                    $pick = $index;

                    break;
                }
            }

            if ($pick === null) {
                $this->pdf->y -= 13.0;
                $slot += $room;

                continue;
            }

            $tile = $queue[$pick];
            unset($queue[$pick]);
            $queue = array_values($queue);

            if ($slot % 3 === 0) {
                $this->pdf->ensure(13.0);
            }

            $x        = PdfPrimitives::MARGIN + $indent + ($slot % 3) * ($cell + $gutter);
            $width    = $tile['span'] * $cell + ($tile['span'] - 1) * $gutter;
            $baseline = $this->pdf->y - 9.5;

            if ($tile['rank'] > 0) {
                $this->pdf->disc($x + 2.5, $baseline + 3.0, 2.2, $tile['rank'] >= 3 ? self::RED : self::AMBER);
            }

            $this->pdf->text($x + 9.0, $baseline, PdfPrimitives::HELVETICA, 9.0, $tile['label']);
            $this->pdf->text($x + $width - $this->pdf->measure($tile['stat'], PdfPrimitives::HELVETICA, 8.0), $baseline, PdfPrimitives::HELVETICA, 8.0, $tile['stat'], self::GRAY);

            $slot += $tile['span'];

            if ($slot % 3 === 0) {
                $this->pdf->y -= 13.0;
            }
        }

        if ($slot % 3 !== 0) {
            $this->pdf->y -= 13.0;
        }

        $this->pdf->y -= 6.0;
    }

    /**
     * @return array{float, float, float}
     */
    private function tone(Tone $tone): array
    {
        return match ($tone) {
            Tone::Success => self::GREEN,
            Tone::Caution => self::AMBER,
            Tone::Danger  => self::RED,
            Tone::Muted   => self::GRAY,
        };
    }

    /**
     * @param list<Run> $runs
     */
    private function runText(array $runs): string
    {
        return implode('', array_map(static fn(Run $run): string => match (true) {
            $run instanceof Code, $run instanceof Strong, $run instanceof Text => $run->content,
            default                                                            => throw new UnexpectedValueException('Unknown run type: ' . $run::class),
        }, $runs));
    }
}
