<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Renderers;

use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Heading;
use LucianoPereira\Crucible\Reporting\Document\Blocks\TileGrid;
use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\FoldingNode;
use LucianoPereira\Crucible\Reporting\Document\Inline\Text;
use LucianoPereira\Crucible\Reporting\Document\Run;
use LucianoPereira\Crucible\Reporting\PrettyName;
use UnexpectedValueException;

use function array_map;
use function count;
use function implode;
use function max;
use function min;
use function round;
use function sprintf;
use function str_repeat;
use function usort;

/**
 * Walks a `Document` to Console's plain-text form. Only the blocks
 * `ConsoleReporter` actually uses for its Passed section
 * (Heading/FoldingTree/TileGrid, D-091's addendum — Problems/Untested
 * stay hand-written, ANSI-colored, unconverted) are implemented; any
 * other block type is a genuine bug, not silently ignored.
 *
 * The tree is rendered as a table: hierarchy in the first column,
 * every number in a column of its own. Figures written into prose
 * (`· 44 tests · 0.646s`) land at a different offset on every row,
 * so no two of them can be compared without being read.
 */
final readonly class ConsoleSummaryRenderer
{
    /** Columns for the tree's own column, connectors included. */
    private const int LABEL = 34;

    /** Cells in the share bar. */
    private const int BAR = 12;

    /** Columns a packed group of names may fill. */
    private const int PACKED = 58;

    /** Share of the run under which a row is a candidate for grouping. */
    private const float FLOOR = 0.01;

    /**
     * Rows worth collapsing into one.
     *
     * ⚠ Below this the group costs what it saves: a heading plus a
     * name list to stand in for two rows is two rows.
     */
    private const int GROUP = 3;

    public function render(Document $document): string
    {
        $output = '';

        foreach ($document->blocks as $block) {
            $output .= $this->block($block);
        }

        return $output;
    }

    private function block(Block $block): string
    {
        return match (true) {
            $block instanceof Heading     => $this->heading($block),
            $block instanceof FoldingTree => $this->foldingTree($block),
            $block instanceof TileGrid    => $this->tileGrid($block),
            default                       => throw new UnexpectedValueException('Unknown block type: ' . $block::class),
        };
    }

    private function heading(Heading $heading): string
    {
        return $this->runText($heading->runs) . "\n";
    }

    private function foldingTree(FoldingTree $tree): string
    {
        // Scaled to the slowest row rather than to the run: one
        // directory holding most of the time leaves every other bar
        // empty, and twenty identical empty bars say nothing.
        $peak  = 0.0;
        $total = 0.0;

        foreach ($tree->visible as $node) {
            $peak = max($peak, $this->peak($node));
            $total += $node->time;
        }

        $out = sprintf(
            "%s%s%s   share\n",
            str_repeat(' ', self::LABEL),
            Str::padLeft('tests', 7),
            Str::padLeft('time', 10),
        );

        $nodes = $tree->visible;
        $last  = count($nodes);
        $index = 0;

        foreach ($nodes as $label => $node) {
            $index++;
            $out .= $this->node($label, $node, '', $index === $last, true, $peak, $total * self::FLOOR);
        }

        if ($tree->foldedDirectoryCount > 0) {
            $out .= $this->row(
                sprintf('%d more %s', $tree->foldedDirectoryCount, $tree->foldedDirectoryCount === 1 ? 'directory' : 'directories'),
                $tree->foldedTestCount,
                $tree->foldedDuration,
                $peak,
            );
        }

        return $out;
    }

    /**
     * One directory, then whatever hangs under it.
     *
     * Children and files are one set here, because a reader scanning
     * for where the time went does not care which of the two a row is.
     */
    private function node(string $label, FoldingNode $node, string $prefix, bool $last, bool $root, float $peak, float $floor): string
    {
        $out = $this->row(
            $prefix . ($root ? '' : ($last ? '└─ ' : '├─ ')) . $label,
            $node->subtreePassedCount(),
            $node->time,
            $peak,
        );

        $inner  = $prefix . ($root ? '' : ($last ? '   ' : '│  '));
        $rows   = $this->entries($node);
        $slow   = [];
        $packed = [];

        foreach ($rows as $row) {
            if ($row['time'] < $floor && !$row['flagged']) {
                $packed[] = $row;

                continue;
            }

            $slow[] = $row;
        }

        // A group of two is a group that saves nothing.
        if (count($packed) < self::GROUP) {
            $slow   = $rows;
            $packed = [];
        }

        $tail = count($slow);

        foreach ($slow as $index => $row) {
            $child = $row['node'];
            $final = $index === $tail - 1 && $packed === [];

            $out .= $child instanceof FoldingNode
                ? $this->node($row['label'], $child, $inner, $final, false, $peak, $floor)
                : $this->row($inner . ($final ? '└─ ' : '├─ ') . $row['label'], $row['tests'], $row['time'], $peak);
        }

        // A group that covers every entry restates the row above it,
        // number for number; the names alone are the whole content.
        return $out . $this->packed($packed, $inner, $peak, $slow !== []);
    }

    /**
     * The fast tail, named rather than counted.
     *
     * ⚠ Grouped, not cut: the rows collapse, the names do not. A line
     * reading "+ 24 more" is the one thing nobody can act on.
     *
     * @param list<array{label: string, tests: int, time: float, flagged: bool, node: FoldingNode|null}> $rows
     */
    private function packed(array $rows, string $prefix, float $peak, bool $summarise): string
    {
        if ($rows === []) {
            return '';
        }

        $tests = 0;
        $time  = 0.0;
        $names = [];

        usort($rows, static fn(array $a, array $b): int => $b['tests'] <=> $a['tests']);

        foreach ($rows as $row) {
            $tests += $row['tests'];
            $time += $row['time'];
            $names[] = sprintf('%s %d', $row['label'], $row['tests']);
        }

        // Named for WHY they are grouped, not for their total: the
        // total is already the row's own time column.
        $out = $summarise
            ? $this->row(
                sprintf('%s└─ %d under %d%%', $prefix, count($rows), (int) round(self::FLOOR * 100)),
                $tests,
                $time,
                $peak,
            )
            : '';

        $line = '';

        foreach ($names as $name) {
            $piece = $line === '' ? $name : '  ·  ' . $name;

            if (Str::width($line) + Str::width($piece) > self::PACKED) {
                $out .= $prefix . '      ' . $line . "\n";
                $line = $name;

                continue;
            }

            $line .= $piece;
        }

        // Never empty: the early return guarantees a row, and every
        // branch of the loop leaves a name in hand.
        return $out . $prefix . '      ' . $line . "\n";
    }

    /**
     * A node's children and its own files, slowest first.
     *
     * @return list<array{label: string, tests: int, time: float, flagged: bool, node: FoldingNode|null}>
     */
    private function entries(FoldingNode $node): array
    {
        $rows = [];

        foreach ($node->children as $label => $child) {
            $rows[] = [
                'label'   => $label,
                'tests'   => $child->subtreePassedCount(),
                'time'    => $child->time,
                'flagged' => $child->holdsFlagged(),
                'node'    => $child,
            ];
        }

        foreach (TileGrid::build($node->files)->tiles as $tile) {
            $rows[] = [
                'label'   => PrettyName::ofFile($tile->file),
                'tests'   => $tile->passedCount,
                'time'    => $tile->duration,
                'flagged' => $tile->rank > 0,
                'node'    => null,
            ];
        }

        usort($rows, static fn(array $a, array $b): int => $b['time'] <=> $a['time']);

        return $rows;
    }

    private function row(string $label, int $tests, float $time, float $peak): string
    {
        // ⚠ Width, not bytes: every connector here is multi-byte, so
        // str_pad() would spend three columns of padding on each one
        // and the numbers would land wherever the depth left them.
        return sprintf(
            "%s%s%s   %s\n",
            Str::padRight(Str::truncate($label, self::LABEL - 1), self::LABEL),
            Str::padLeft((string) $tests, 7),
            Str::padLeft(sprintf('%.3fs', $time), 10),
            $this->bar($time, $peak),
        );
    }

    private function bar(float $time, float $peak): string
    {
        $filled = $peak <= 0.0 ? 0 : min(self::BAR, (int) round($time / $peak * self::BAR));

        return str_repeat('█', $filled) . str_repeat('·', self::BAR - $filled);
    }

    /** The slowest row anywhere under a node, which sets the bar's scale. */
    private function peak(FoldingNode $node): float
    {
        $peak = 0.0;

        foreach ($this->entries($node) as $row) {
            $peak = max($peak, $row['time']);

            if ($row['node'] instanceof FoldingNode) {
                $peak = max($peak, $this->peak($row['node']));
            }
        }

        return $peak;
    }

    private function tileGrid(TileGrid $grid, int $depth = 1): string
    {
        $indent = str_repeat('  ', $depth);
        $out    = '';

        foreach ($grid->tiles as $tile) {
            $out .= sprintf("%s%s — %d %s\n", $indent, PrettyName::ofFile($tile->file), $tile->passedCount, $tile->passedCount === 1 ? 'test' : 'tests');
        }

        return $out;
    }

    /**
     * @param list<Run> $runs
     */
    private function runText(array $runs): string
    {
        return implode('', array_map(static fn(Run $run): string => $run instanceof Text ? $run->content : throw new UnexpectedValueException('Unknown run type: ' . $run::class), $runs));
    }
}
