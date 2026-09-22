<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function count;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function ksort;
use function max;
use function sort;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * A run's collected coverage (D-041), at the two granularities the
 * consumers need: aggregate line values per file (reports), and the
 * project files each test executed (the impact map, and later the
 * DeFlaker check and mutation targeting). Mergeable, because workers
 * each produce one and the supervisor combines them — coverage
 * crosses the process boundary as artifact files, not events: it is
 * bulk data, like the result cache, not protocol.
 */
final class CoverageData
{
    /**
     * @param array<string, array<int, int>>                           $lines    file => line => value (1 covered, -1 missed, -2 dead)
     * @param array<string, array<string, list<int>>>                  $tests    test id => executed file => executed lines (D-047)
     * @param array<string, array<string, array{line: int, hit: int}>> $branches file => branch id => first line + hit flag (D-062, xdebug only)
     * @param array<string, array<string, array{line: int, hit: int}>> $paths    file => path id => the same shape (--path-coverage, xdebug only)
     */
    public function __construct(
        public array $lines = [],
        public array $tests = [],
        public array $branches = [],
        public array $paths = [],
    ) {}

    /**
     * Folds one test's collection in. The per-test executed map comes
     * from the OBSERVED window (D-047 — the mutation query and the
     * impact graph want truth, not the covers claim); the aggregate
     * line values merge by max (any execution wins over -1) from the
     * AGGREGATE window — covers-filtered, hit-demoted when the test
     * settled risky (D-063). A file counts as the test's only when a
     * line actually RAN — drivers report every loaded file's
     * unexecuted (-1) lines in every window, and "loaded" is not
     * "executed".
     *
     * @param non-empty-string $testId
     */
    public function record(string $testId, CoverageWindow $observed, ?CoverageWindow $aggregate = null): void
    {
        $aggregate ??= $observed;
        $executed = [];

        foreach ($observed->lines as $file => $lines) {
            $ran = [];

            foreach ($lines as $line => $value) {
                if ($value > 0) {
                    $ran[] = $line;
                }
            }

            if ($ran !== []) {
                sort($ran);

                $executed[$file] = $ran;
            }
        }

        $this->tests[$testId] = $executed;

        foreach ($aggregate->lines as $file => $lines) {
            foreach ($lines as $line => $value) {
                $this->lines[$file][$line] = max($this->lines[$file][$line] ?? $value, $value);
            }
        }

        $this->branches = $this->mergedHits($this->branches, $aggregate->branches);
        $this->paths    = $this->mergedHits($this->paths, $aggregate->paths);
    }

    public function merge(self $other): void
    {
        foreach ($other->lines as $file => $lines) {
            foreach ($lines as $line => $value) {
                $this->lines[$file][$line] = max($this->lines[$file][$line] ?? $value, $value);
            }
        }

        foreach ($other->tests as $testId => $files) {
            $this->tests[$testId] = $files;
        }

        $this->branches = $this->mergedHits($this->branches, $other->branches);
        $this->paths    = $this->mergedHits($this->paths, $other->paths);
    }

    /**
     * Branch and path entries merge like line values: any hit wins
     * (D-062) — one worker reaching a block is enough for the run.
     *
     * @param array<string, array<string, array{line: int, hit: int}>> $into
     * @param array<string, array<string, array{line: int, hit: int}>> $from
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    private function mergedHits(array $into, array $from): array
    {
        foreach ($from as $file => $entries) {
            foreach ($entries as $id => $entry) {
                $known = $into[$file][$id] ?? null;

                $into[$file][$id] = [
                    'line' => $entry['line'],
                    'hit'  => max($known['hit'] ?? 0, $entry['hit']),
                ];
            }
        }

        return $into;
    }

    /**
     * @param array<string, array{line: int, hit: int}> $entries
     *
     * @return array{covered: int, total: int}
     */
    public static function branchCounts(array $entries): array
    {
        $covered = 0;

        foreach ($entries as $branch) {
            if ($branch['hit'] > 0) {
                $covered++;
            }
        }

        return ['covered' => $covered, 'total' => count($entries)];
    }

    /**
     * @return array{covered: int, total: int}
     */
    public function branchTotals(): array
    {
        $covered = 0;
        $total   = 0;

        foreach ($this->branches as $entries) {
            $counts = self::branchCounts($entries);
            $covered += $counts['covered'];
            $total += $counts['total'];
        }

        return ['covered' => $covered, 'total' => $total];
    }

    /**
     * The same tally over whole routes through a function rather than
     * single blocks of one (--path-coverage).
     *
     * @return array{covered: int, total: int}
     */
    public function pathTotals(): array
    {
        $covered = 0;
        $total   = 0;

        foreach ($this->paths as $entries) {
            $counts = self::branchCounts($entries);
            $covered += $counts['covered'];
            $total += $counts['total'];
        }

        return ['covered' => $covered, 'total' => $total];
    }

    /**
     * @return array{covered: int, executable: int}
     */
    public function totals(): array
    {
        $covered    = 0;
        $executable = 0;

        foreach ($this->lines as $lines) {
            foreach ($lines as $value) {
                if ($value === -2) {
                    continue;
                }

                $executable++;

                if ($value > 0) {
                    $covered++;
                }
            }
        }

        return ['covered' => $covered, 'executable' => $executable];
    }

    public function toJson(): string
    {
        $lines = $this->lines;

        foreach ($lines as &$values) {
            ksort($values);
        }

        unset($values);

        $payload = ['lines' => $lines, 'tests' => $this->tests];

        if ($this->branches !== []) {
            $payload['branches'] = $this->branches;
        }

        if ($this->paths !== []) {
            $payload['paths'] = $this->paths;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);

        if (!is_array($decoded)) {
            return new self();
        }

        $lines = [];

        foreach (is_array($decoded['lines'] ?? null) ? $decoded['lines'] : [] as $file => $values) {
            if (!is_string($file) || !is_array($values)) {
                continue;
            }

            foreach ($values as $line => $value) {
                if (is_int($line) && is_int($value)) {
                    $lines[$file][$line] = $value;
                }
            }
        }

        $tests = [];

        foreach (is_array($decoded['tests'] ?? null) ? $decoded['tests'] : [] as $testId => $files) {
            if (!is_string($testId) || !is_array($files)) {
                continue;
            }

            $perFile = [];

            foreach ($files as $file => $executed) {
                if (!is_string($file) || $file === '' || !is_array($executed)) {
                    continue;
                }

                $list = [];

                foreach ($executed as $line) {
                    if (is_int($line)) {
                        $list[] = $line;
                    }
                }

                if ($list !== []) {
                    $perFile[$file] = $list;
                }
            }

            $tests[$testId] = $perFile;
        }

        return new self(
            $lines,
            $tests,
            self::hitsFrom($decoded['branches'] ?? null),
            self::hitsFrom($decoded['paths'] ?? null),
        );
    }

    /**
     * Decoded JSON, so every entry is validated on the way in rather
     * than assumed — a worker artifact is a file on disk like any other.
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    private static function hitsFrom(mixed $decoded): array
    {
        $entries = [];

        foreach (is_array($decoded) ? $decoded : [] as $file => $byId) {
            if (!is_string($file) || $file === '' || !is_array($byId)) {
                continue;
            }

            foreach ($byId as $id => $entry) {
                if (!is_string($id) || !is_array($entry) || !is_int($entry['line'] ?? null) || !is_int($entry['hit'] ?? null)) {
                    continue;
                }

                $entries[$file][$id] = ['line' => $entry['line'], 'hit' => $entry['hit']];
            }
        }

        return $entries;
    }
}
