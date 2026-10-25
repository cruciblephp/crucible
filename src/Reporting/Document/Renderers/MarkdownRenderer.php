<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Renderers;

use LucianoPereira\Crucible\Event\Outcome;
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
use LucianoPereira\Crucible\Reporting\Document\Run;
use LucianoPereira\Crucible\Reporting\Document\Tone;
use LucianoPereira\Crucible\Reporting\PrettyName;
use UnexpectedValueException;

use function array_filter;
use function array_map;
use function implode;
use function sprintf;
use function str_repeat;
use function str_replace;

/**
 * Walks a `Document` to Markdown text — the same
 * `match (true) { $block instanceof X => ... }` dispatch as the lrv
 * pattern this is modeled on (D-091's addendum), one method per
 * block/run type.
 */
final readonly class MarkdownRenderer
{
    public function render(Document $document): string
    {
        $output = '';

        foreach ($document->blocks as $block) {
            $output .= $this->block($block);
        }

        return $output;
    }

    /** @phpcpd-ignore-clone Dispatch table — one arm per block type, one table per output format by design. */
    private function block(Block $block): string
    {
        return match (true) {
            $block instanceof Heading       => $this->heading($block),
            $block instanceof Paragraph     => $this->paragraph($block),
            $block instanceof BulletList    => $this->bulletList($block),
            $block instanceof Badge         => $this->badge($block),
            $block instanceof ProblemList   => $this->problemList($block),
            $block instanceof ProportionBar => $this->proportionBar($block),
            $block instanceof RankedList    => $this->rankedList($block),
            $block instanceof FoldingTree   => $this->foldingTree($block),
            $block instanceof TileGrid      => $this->tileGrid($block),
            $block instanceof Toc           => '<!-- toc -->' . "\n\n",
            $block instanceof Image, $block instanceof Svg
                                            => "_An image is included here (not embeddable in Markdown)._\n\n",
            $block instanceof PageBreak     => "---\n\n",
            default                         => throw new UnexpectedValueException('Unknown block type: ' . $block::class),
        };
    }

    private function heading(Heading $heading): string
    {
        return str_repeat('#', $heading->level) . ' ' . $this->runs($heading->runs) . "\n\n";
    }

    private function paragraph(Paragraph $paragraph): string
    {
        return $this->runs($paragraph->runs) . "\n\n";
    }

    private function bulletList(BulletList $list): string
    {
        $out = '';

        foreach ($list->items as $item) {
            $out .= '- ' . $this->runs($item) . "\n";
        }

        return $out . "\n";
    }

    private function badge(Badge $badge): string
    {
        return '**' . $badge->text . "**\n\n";
    }

    private function problemList(ProblemList $list): string
    {
        $out = '';

        foreach ($list->entries as $entry) {
            $out .= sprintf(
                "### `%s` — %s\n\n",
                $entry->testName,
                $entry->outcome,
            );

            if ($entry->detail !== null) {
                $out .= "```\n" . $entry->detail . "\n```\n\n";
            }
        }

        return $out;
    }

    /**
     * The passed/skipped/flagged/failed share strip — a plain
     * percentage line, since Markdown has no visual bar to draw.
     */
    private function proportionBar(ProportionBar $bar): string
    {
        $total = 0;

        foreach ($bar->shares as $counted) {
            $total += $counted->count;
        }

        if ($total === 0) {
            return '';
        }

        $parts = [];

        foreach ($bar->shares as $share) {
            if ($share->count === 0) {
                continue;
            }

            $parts[] = sprintf('%s %.0f%%', $this->toneLabel($share->tone), $share->count / $total * 100);
        }

        return implode(' · ', $parts) . "\n\n";
    }

    private function toneLabel(Tone $tone): string
    {
        return match ($tone) {
            Tone::Success => 'Passed',
            Tone::Muted   => 'Skipped',
            Tone::Caution => 'Flagged',
            Tone::Danger  => 'Failed',
        };
    }

    private function rankedList(RankedList $list): string
    {
        if ($list->entries === []) {
            return '';
        }

        $out = '';

        foreach ($list->entries as $index => $entry) {
            $out .= sprintf(
                "%d. %s · %s — %.3fs\n",
                $index + 1,
                $this->cell($entry->testName),
                $this->cell(PrettyName::ofFile($entry->file)),
                $entry->duration,
            );
        }

        return $out . "\n";
    }

    private function foldingTree(FoldingTree $tree): string
    {
        $out = '';

        foreach ($tree->visible as $label => $node) {
            $out .= $this->node($label, $node, $tree->timed);
        }

        if ($tree->foldedDirectoryCount > 0) {
            $out .= sprintf(
                "+ %d more %s · %d %s%s\n\n",
                $tree->foldedDirectoryCount,
                $tree->foldedDirectoryCount === 1 ? 'directory' : 'directories',
                $tree->foldedTestCount,
                $tree->foldedTestCount === 1 ? 'test' : 'tests',
                $tree->timed ? sprintf(' · %.3fs', $tree->foldedDuration) : '',
            );
        }

        return $out;
    }

    private function node(string $label, FoldingNode $node, bool $timed): string
    {
        $own   = $node->ownPassedCount();
        $count = $own > 0 ? sprintf('%d %s', $own, $own === 1 ? 'test' : 'tests') : '';
        $time  = $timed ? sprintf('%.3fs', $node->time) : '';
        $meta  = implode(', ', array_filter([$count, $time], static fn(string $part): bool => $part !== ''));
        $out   = $meta === '' ? sprintf("#### %s\n\n", $label) : sprintf("#### %s (%s)\n\n", $label, $meta);

        // Every individual test, not just the file's aggregate — the
        // same per-test table the old flat "## Results" section
        // showed, now organized under the directory tree instead of
        // losing that detail to match Pdf's compact tile grid.
        foreach ($node->files as $file => $tests) {
            $out .= sprintf("##### %s\n\n", PrettyName::ofFile($file));
            $out .= $timed ? "| Test | Outcome | Time |\n|------|---------|-----:|\n" : "| Test | Outcome |\n|------|---------|\n";

            foreach ($tests as $test) {
                $out .= sprintf(
                    "| %s | %s %s |%s\n",
                    $this->cell(PrettyName::ofTest($test->test)),
                    $this->mark($test->outcome),
                    $test->outcome->value,
                    $timed ? sprintf(' %.3fs |', $test->duration) : '',
                );
            }

            $out .= "\n";
        }

        foreach ($node->children as $childLabel => $child) {
            $out .= $this->node($childLabel, $child, $timed);
        }

        return $out;
    }

    /**
     * @return non-empty-string
     */
    private function mark(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::Passed     => '✅',
            Outcome::Failed     => '❌',
            Outcome::Errored    => '💥',
            Outcome::Skipped    => '⏭️',
            Outcome::Incomplete => '🚧',
            Outcome::Risky      => '⚠️',
        };
    }

    private function tileGrid(TileGrid $grid): string
    {
        $out = '';

        foreach ($grid->tiles as $tile) {
            $out .= sprintf(
                "- %s — %d %s%s\n",
                PrettyName::ofFile($tile->file),
                $tile->passedCount,
                $tile->passedCount === 1 ? 'test' : 'tests',
                $grid->timed ? sprintf(' (%.3fs)', $tile->duration) : '',
            );
        }

        return $out . "\n";
    }

    /**
     * @param list<Run> $runs
     */
    private function runs(array $runs): string
    {
        return implode('', array_map(fn(Run $run): string => match (true) {
            $run instanceof Code   => '`' . $this->cell($run->content) . '`',
            $run instanceof Strong => '**' . $this->cell($run->content) . '**',
            $run instanceof Text   => $this->cell($run->content),
            default                => throw new UnexpectedValueException('Unknown run type: ' . $run::class),
        }, $runs));
    }

    /**
     * Table cells and inline text cannot contain unescaped pipes or
     * newlines.
     */
    private function cell(string $value): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $value);
    }
}
