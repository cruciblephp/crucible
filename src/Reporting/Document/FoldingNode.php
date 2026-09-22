<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;

use function count;
use function max;

/**
 * One directory in a `FoldingTree` — its own files (if any), its
 * subtree time, and its child directories. Single-child, file-less
 * chains are collapsed into one label ("tests/unit") before this
 * tree is built, and siblings are ordered by descending subtree time
 * — the same shape `PdfWriter::forest()`/`branch()` built inline
 * before D-091's addendum extracted it as shared content.
 */
final readonly class FoldingNode
{
    /**
     * @param array<non-empty-string, list<TestFinished>> $files
     * @param array<string, FoldingNode>                  $children
     */
    public function __construct(
        public string $label,
        public float $time,
        public array $files,
        public array $children,
    ) {}

    /**
     * Passed count across this node's own files only — children carry
     * their own.
     */
    public function ownPassedCount(): int
    {
        $passed = 0;

        foreach ($this->files as $tests) {
            $passed += self::passedCountOf($tests);
        }

        return $passed;
    }

    /**
     * Passed count across this node and every descendant.
     */
    public function subtreePassedCount(): int
    {
        $passed = $this->ownPassedCount();

        foreach ($this->children as $child) {
            $passed += $child->subtreePassedCount();
        }

        return $passed;
    }

    /**
     * Every non-passed test in this node and its descendants — SARIF
     * and JSON need the raw event (file, failure trace) that
     * `ProblemEntry` doesn't carry; `holdsFlagged()` already
     * guarantees folding never hides a problem, so this recursion
     * never misses one.
     *
     * @return list<TestFinished>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ($this->files as $tests) {
            foreach ($tests as $test) {
                if ($test->outcome !== Outcome::Passed) {
                    $problems[] = $test;
                }
            }
        }

        foreach ($this->children as $child) {
            foreach ($child->problems() as $test) {
                $problems[] = $test;
            }
        }

        return $problems;
    }

    /**
     * Whether this subtree contains any non-passing file — protects a
     * directory referenced in Problems from ever folding away.
     */
    public function holdsFlagged(): bool
    {
        foreach ($this->files as $tests) {
            if (self::rankOf($tests) > 0) {
                return true;
            }
        }

        foreach ($this->children as $child) {
            if ($child->holdsFlagged()) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when every child is a file-less... no — a single-file leaf
     * with no children of its own, so the whole set can inline as
     * flowing lines instead of nested headings.
     *
     * @param array<string, FoldingNode> $children
     */
    public static function onlyInlinable(array $children): bool
    {
        foreach ($children as $child) {
            if ($child->children !== [] || count($child->files) !== 1) {
                return false;
            }
        }

        return $children !== [];
    }

    /**
     * @param list<TestFinished> $tests
     */
    public static function passedCountOf(array $tests): int
    {
        $passed = 0;

        foreach ($tests as $test) {
            if ($test->outcome === Outcome::Passed) {
                $passed++;
            }
        }

        return $passed;
    }

    /**
     * @param list<TestFinished> $tests
     */
    public static function timeOf(array $tests): float
    {
        $total = 0.0;

        foreach ($tests as $test) {
            $total += $test->duration;
        }

        return $total;
    }

    /**
     * A file's severity: 3 = has failures/errors, 2 = has
     * incompletes/risky, 1 = has skips, 0 = all pass — what a tile's
     * dot color derives from.
     *
     * @param list<TestFinished> $tests
     *
     * @return int<0, 3>
     */
    public static function rankOf(array $tests): int
    {
        $rank = static fn(Outcome $outcome): int => match ($outcome) {
            Outcome::Errored, Outcome::Failed   => 3,
            Outcome::Incomplete, Outcome::Risky => 2,
            Outcome::Skipped                    => 1,
            Outcome::Passed                     => 0,
        };

        $top = 0;

        foreach ($tests as $test) {
            $top = max($top, $rank($test->outcome));
        }

        return $top;
    }
}
