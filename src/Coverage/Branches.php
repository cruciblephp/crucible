<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function is_array;
use function is_int;
use function is_string;

/**
 * Normalizes xdebug's branch-analysis payload (D-062): under
 * XDEBUG_CC_BRANCH_CHECK each file entry becomes
 * {lines, functions: {fn: {branches: {opIndex: {...}}, paths}}}.
 * A branch is one basic block; it is covered when it was entered
 * (hit > 0) — the ecosystem's branch metric. Paths are the same
 * payload's other half: a whole route through a function rather than
 * one block of it, collected only when --path-coverage asks, because
 * the count is combinatorial in the branches.
 */
final readonly class Branches
{
    /**
     * @param array<mixed> $collected raw driver payload
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    public static function normalize(array $collected): array
    {
        $normalized = [];

        foreach (self::functions($collected, 'branches') as [$file, $function, , $branches]) {
            foreach ($branches as $opIndex => $branch) {
                if (!is_int($opIndex) || !is_array($branch)) {
                    continue;
                }

                $line = $branch['line_start'] ?? null;
                $hit  = $branch['hit'] ?? null;

                if (is_int($line) && is_int($hit)) {
                    $normalized[$file][$function . '@' . $opIndex] = ['line' => $line, 'hit' => $hit];
                }
            }
        }

        return $normalized;
    }

    /**
     * Xdebug's path analysis, in the same shape as a branch so every
     * consumer that can count branches can count paths unchanged. A
     * path has no line of its own; it takes its function's first
     * branch line, which is where a report would point anyway.
     *
     * @param array<mixed> $collected raw driver payload
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    public static function paths(array $collected): array
    {
        $normalized = [];

        foreach (self::functions($collected, 'paths') as [$file, $function, $analysis, $paths]) {
            $line = self::firstLine($analysis);

            foreach ($paths as $index => $path) {
                if (!is_int($index) || !is_array($path)) {
                    continue;
                }

                $hit = $path['hit'] ?? null;

                if (is_int($hit)) {
                    $normalized[$file][$function . '#' . $index] = ['line' => $line, 'hit' => $hit];
                }
            }
        }

        return $normalized;
    }

    /**
     * The guard walk both readings share, yielding the requested section
     * already narrowed so each caller sees only what it counts.
     *
     * @param array<mixed>     $collected raw driver payload
     * @param non-empty-string $section   'branches' or 'paths'
     *
     * @return iterable<array{0: non-empty-string, 1: string, 2: array<mixed>, 3: array<mixed>}>
     */
    private static function functions(array $collected, string $section): iterable
    {
        foreach ($collected as $file => $entry) {
            if (!is_string($file) || $file === '' || !is_array($entry) || !is_array($entry['functions'] ?? null)) {
                continue;
            }

            foreach ($entry['functions'] as $function => $analysis) {
                if (!is_string($function) || !is_array($analysis) || !is_array($analysis[$section] ?? null)) {
                    continue;
                }

                yield [$file, $function, $analysis, $analysis[$section]];
            }
        }
    }

    /**
     * @param array<mixed> $analysis
     */
    private static function firstLine(array $analysis): int
    {
        foreach (is_array($analysis['branches'] ?? null) ? $analysis['branches'] : [] as $branch) {
            if (is_array($branch) && is_int($branch['line_start'] ?? null)) {
                return $branch['line_start'];
            }
        }

        return 0;
    }
}
