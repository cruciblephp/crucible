<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\FoldingNode;
use LucianoPereira\Crucible\Test\TestId;

use function count;
use function explode;
use function ltrim;
use function str_starts_with;
use function strlen;
use function strrpos;
use function substr;
use function uasort;

/**
 * The passed-tests directory tree, sub-threshold tail folded into one
 * closing summary. Ported verbatim from `PdfWriter::forest()`/
 * `branch()` and the fold decision in its old `passedSection()`
 * (D-091's addendum) — the same content, now a renderer-agnostic
 * `Block` every format-specific renderer walks its own way, since
 * only PDF paginates.
 */
final readonly class FoldingTree implements Block
{
    /**
     * @param array<string, FoldingNode> $visible
     */
    public function __construct(
        public array $visible,
        public int $foldedDirectoryCount,
        public int $foldedTestCount,
        public float $foldedDuration,
    ) {}

    /**
     * Every non-passed test across the whole tree — see
     * {@see FoldingNode::problems()}.
     *
     * @return list<TestFinished>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->visible as $node) {
            foreach ($node->problems() as $test) {
                $problems[] = $test;
            }
        }

        return $problems;
    }

    /**
     * @param array<non-empty-string, list<TestFinished>> $sections
     */
    public static function build(array $sections, float $runDuration): self
    {
        $visible = [];
        $folded  = [];
        $folding = false;

        // The sub-threshold tail folds: once a top-level directory
        // falls below both 1ms and 0.1% of the runtime, it and
        // everything after it (the order is descending) collapse
        // into one closing line — except directories holding flagged
        // files: nothing referenced in Problems may disappear. A
        // fold of one is pointless; the line costs the same.
        foreach (self::forest($sections) as $label => $node) {
            $folding = $folding || ($node->time < 0.001 && $node->time < 0.001 * $runDuration);

            if ($folding && !$node->holdsFlagged()) {
                $folded[$label] = $node;

                continue;
            }

            $visible[$label] = $node;
        }

        if (count($folded) === 1) {
            foreach ($folded as $label => $node) {
                $visible[$label] = $node;
            }

            $folded = [];
        }

        $foldedTests = 0;
        $foldedTime  = 0.0;

        foreach ($folded as $node) {
            $foldedTime += $node->time;
            $foldedTests += $node->subtreePassedCount();
        }

        return new self($visible, count($folded), $foldedTests, $foldedTime);
    }

    public static function sample(): self
    {
        $sections = [
            'tests/unit/MathTest.php' => [
                new TestFinished(new TestId('tests/unit/MathTest.php', 'testAdds'), Outcome::Passed, 0.010),
            ],
            'tests/unit/CacheTest.php' => [
                new TestFinished(new TestId('tests/unit/CacheTest.php', 'testRoundTrips'), Outcome::Passed, 0.020),
            ],
        ];

        return self::build($sections, 0.030);
    }

    /**
     * The directory forest: every directory exactly once, children
     * nested under their parents, single-child chains collapsed into
     * one label ("tests/unit/"), siblings ordered by descending
     * subtree time.
     *
     * @param array<non-empty-string, list<TestFinished>> $sections
     *
     * @return array<string, FoldingNode>
     */
    private static function forest(array $sections): array
    {
        /** @var array<string, array<non-empty-string, list<TestFinished>>> $dirs */
        $dirs = [];

        foreach ($sections as $file => $tests) {
            $slash = strrpos($file, '/');
            $dir   = $slash === false ? '.' : substr($file, 0, $slash);

            $dirs[$dir][$file] = $tests;
        }

        return self::branch($dirs, '');
    }

    /**
     * The nodes directly under $prefix: each next segment becomes one
     * node holding its own files, its subtree, and its subtree time;
     * file-less single-child chains collapse into one label, and
     * siblings sort by descending time.
     *
     * @param array<string, array<non-empty-string, list<TestFinished>>> $dirs directory (no trailing slash) => files
     *
     * @return array<string, FoldingNode>
     */
    private static function branch(array $dirs, string $prefix): array
    {
        /** @var array<string, array<string, array<non-empty-string, list<TestFinished>>>> $bySegment */
        $bySegment = [];

        foreach ($dirs as $dir => $files) {
            $relative = $prefix === ''
                ? $dir
                : (str_starts_with($dir, $prefix . '/') ? substr($dir, strlen($prefix) + 1) : null);

            if ($relative === null || $relative === '') {
                continue;
            }

            $segment = explode('/', $relative)[0];

            // An absolute path's first segment is empty ("/home/x"
            // explodes to ["", "home", "x"]), which leaves $path equal
            // to $prefix and recurses on unchanged arguments forever.
            // The leading separator belongs to the segment.
            if ($segment === '') {
                $segment = '/' . explode('/', ltrim($relative, '/'))[0];
            }

            $bySegment[$segment][$dir] = $files;
        }

        $nodes = [];

        foreach ($bySegment as $segment => $subset) {
            $path     = $prefix === '' ? $segment : $prefix . '/' . $segment;
            $label    = $segment;
            $files    = $subset[$path] ?? [];
            $children = self::branch($subset, $path);

            // Collapse the file-less single-child chain into one
            // label ("tests/unit") — internal separators kept, no
            // trailing slash anywhere.
            while ($files === [] && count($children) === 1) {
                foreach ($children as $childLabel => $child) {
                    $label .= '/' . $childLabel;
                    $files    = $child->files;
                    $children = $child->children;
                }
            }

            $time = 0.0;

            foreach ($files as $tests) {
                $time += FoldingNode::timeOf($tests);
            }

            foreach ($children as $child) {
                $time += $child->time;
            }

            $nodes[$label] = new FoldingNode($label, $time, $files, $children);
        }

        uasort($nodes, static fn(FoldingNode $a, FoldingNode $b): int => $b->time <=> $a->time);

        return $nodes;
    }
}
