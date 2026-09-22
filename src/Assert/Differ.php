<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

use function count;
use function explode;
use function implode;
use function max;
use function sprintf;

/**
 * Line diff between two exported values, rendered with -/+ markers
 * and Expected/Actual annotations (the jest-diff presentation model).
 *
 * Classic LCS dynamic programming — quadratic, fine for failure-sized
 * values; inputs beyond the guard render without a diff. A histogram
 * implementation can replace this internally without changing output
 * contract consumers see.
 */
final class Differ
{
    private const int MAX_LINES = 2000;

    /**
     * How many unchanged lines to keep around each change (the spec's
     * --diff-context); null keeps all of them, which is the default and
     * what every existing consumer sees. Run-wide display state, set once
     * from the command line before any test runs and never per test, so it
     * cannot leak between them the way a per-test static would.
     */
    private static ?int $context = null;

    public static function context(?int $lines): void
    {
        // The Differ owns the invariant rather than trusting its caller.
        // Zero is a real request — show the changed lines and nothing else
        // — so a negative clamps to it rather than to "no limit".
        self::$context = $lines === null ? null : max(0, $lines);
    }

    public static function diff(string $expected, string $actual): string
    {
        $from = explode("\n", $expected);
        $to   = explode("\n", $actual);

        if (count($from) > self::MAX_LINES || count($to) > self::MAX_LINES) {
            return "--- Expected\n+++ Actual\n(diff omitted: value too large)";
        }

        $lines = ['--- Expected', '+++ Actual'];

        foreach (self::trimmed(self::opcodes($from, $to)) as [$op, $line]) {
            $lines[] = $op . $line;
        }

        return implode("\n", $lines);
    }

    /**
     * Collapses runs of unchanged lines to the configured context, the way
     * a unified diff does: keep N on each side of a change, replace the
     * rest with one line saying how many were dropped.
     *
     * @param list<array{string, string}> $ops
     *
     * @return list<array{string, string}>
     */
    private static function trimmed(array $ops): array
    {
        $context = self::$context;

        if ($context === null) {
            return $ops;
        }

        $keep  = [];
        $total = count($ops);

        foreach ($ops as $index => [$op]) {
            if ($op !== ' ') {
                $keep[$index] = true;

                continue;
            }

            for ($near = $index - $context; $near <= $index + $context; $near++) {
                if ($near >= 0 && $near < $total && $ops[$near][0] !== ' ') {
                    $keep[$index] = true;

                    break;
                }
            }
        }

        $trimmed = [];
        $dropped = 0;

        foreach ($ops as $index => $op) {
            if (isset($keep[$index])) {
                if ($dropped > 0) {
                    $trimmed[] = [' ', sprintf('@@ %d unchanged line(s) omitted @@', $dropped)];
                    $dropped   = 0;
                }

                $trimmed[] = $op;

                continue;
            }

            $dropped++;
        }

        if ($dropped > 0) {
            $trimmed[] = [' ', sprintf('@@ %d unchanged line(s) omitted @@', $dropped)];
        }

        return $trimmed;
    }

    /**
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return list<array{string, string}> pairs of marker ('-', '+', ' ') and line
     */
    private static function opcodes(array $from, array $to): array
    {
        $n = count($from);
        $m = count($to);

        // LCS length table.
        /** @var array<int, array<int, int>> $table */
        $table = [];

        for ($i = $n; $i >= 0; $i--) {
            for ($j = $m; $j >= 0; $j--) {
                if ($i === $n || $j === $m) {
                    $table[$i][$j] = 0;

                    continue;
                }

                $table[$i][$j] = $from[$i] === $to[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : (max($table[$i + 1][$j], $table[$i][$j + 1]));
            }
        }

        $ops = [];
        $i   = 0;
        $j   = 0;

        while ($i < $n && $j < $m) {
            if ($from[$i] === $to[$j]) {
                $ops[] = [' ', $from[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $ops[] = ['-', $from[$i]];
                $i++;
            } else {
                $ops[] = ['+', $to[$j]];
                $j++;
            }
        }

        while ($i < $n) {
            $ops[] = ['-', $from[$i]];
            $i++;
        }

        while ($j < $m) {
            $ops[] = ['+', $to[$j]];
            $j++;
        }

        return $ops;
    }
}
