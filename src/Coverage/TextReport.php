<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

use function array_key_last;
use function array_slice;
use function count;
use function implode;
use function ksort;
use function sort;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The console coverage summary (D-041): one line per source file,
 * project-relative, plus the total — enough to watch coverage move
 * without leaving the terminal. Richer formats are separate writers.
 */
final readonly class TextReport
{
    private const int RUNS = 10;

    /**
     * @param bool $onlySummary   the spec's --only-summary-for-coverage-text: totals, no per-file rows
     * @param bool $showUncovered the spec's --show-uncovered-for-coverage-text: keep the 0% files a report otherwise elides
     */
    public function __construct(
        private bool $onlySummary = false,
        private bool $showUncovered = false,
    ) {}

    /**
     * @param non-empty-string $root absolute project root
     * @param non-empty-string $driver
     */
    public function render(CoverageData $data, string $root, string $driver): string
    {
        $prefix    = $root . '/';
        $rows      = [];
        $uncovered = 0;

        foreach ($data->lines as $file => $lines) {
            $covered    = 0;
            $executable = 0;

            foreach ($lines as $value) {
                if ($value === -2) {
                    continue;
                }

                $executable++;

                if ($value > 0) {
                    $covered++;
                }
            }

            $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

            // A file nothing touched is noise in a coverage listing and
            // the reason the spec has a switch for it: elided by default,
            // kept whole when --show-uncovered-for-coverage-text asks.
            if ($covered === 0 && $executable > 0 && !$this->showUncovered) {
                $uncovered++;

                continue;
            }

            $rows[$relative] = [$covered, $executable, CoverageData::branchCounts($data->branches[$file] ?? []), self::missed($lines)];
        }

        ksort($rows);

        $withBranches = $data->branches !== [];
        $report       = sprintf("Coverage (%s):\n", $driver);

        foreach ($this->onlySummary ? [] : $rows as $file => [$covered, $executable, $branches, $missed]) {
            $report .= sprintf(
                "  %6.2f%%  %s  (%d/%d)%s%s\n",
                CoveragePercent::of($covered, $executable),
                $file,
                $covered,
                $executable,
                $missed === '' ? '' : '  ' . $missed,
                $withBranches && $branches['total'] > 0
                    ? sprintf('  branches %.2f%% (%d/%d)', CoveragePercent::of($branches['covered'], $branches['total']), $branches['covered'], $branches['total'])
                    : '',
            );
        }

        if ($uncovered > 0 && !$this->onlySummary) {
            $report .= sprintf("  %d file(s) with no covered line omitted; --show-uncovered-for-coverage-text lists them.\n", $uncovered);
        }

        $totals = $data->totals();
        $report .= sprintf(
            "  Total: %.2f%% (%d of %d executable lines)\n",
            CoveragePercent::of($totals['covered'], $totals['executable']),
            $totals['covered'],
            $totals['executable'],
        );

        if (!$withBranches) {
            return $report;
        }

        $branchTotals = $data->branchTotals();

        $report .= sprintf(
            "  Branches: %.2f%% (%d of %d)\n",
            CoveragePercent::of($branchTotals['covered'], $branchTotals['total']),
            $branchTotals['covered'],
            $branchTotals['total'],
        );

        $pathTotals = $data->pathTotals();

        if ($pathTotals['total'] === 0) {
            return $report;
        }

        return $report . sprintf(
            "  Paths: %.2f%% (%d of %d)\n",
            CoveragePercent::of($pathTotals['covered'], $pathTotals['total']),
            $pathTotals['covered'],
            $pathTotals['total'],
        );
    }

    /**
     * The executable lines nobody ran, as runs and singles: `12..18, 30`.
     * A dead line is not executable and never listed; two missed lines with
     * one between them are two entries, since they are not consecutive. A
     * row stops at ten entries and counts the rest, so it never wraps a
     * terminal into noise.
     *
     * @param array<int, int> $lines line => value
     */
    public static function missed(array $lines): string
    {
        $missed = [];

        foreach ($lines as $line => $value) {
            if ($value === -1) {
                $missed[] = $line;
            }
        }

        sort($missed);

        $runs = [];

        foreach ($missed as $line) {
            $last = array_key_last($runs);

            if ($last !== null && $runs[$last][1] === $line - 1) {
                $runs[$last][1] = $line;
            } else {
                $runs[] = [$line, $line];
            }
        }

        $written = [];

        foreach (array_slice($runs, 0, self::RUNS) as [$from, $to]) {
            $written[] = $from === $to ? (string) $from : $from . '..' . $to;
        }

        if (count($runs) > self::RUNS) {
            $written[] = sprintf('… and %d more', count($runs) - self::RUNS);
        }

        return implode(', ', $written);
    }
}
