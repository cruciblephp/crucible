<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function array_map;
use function ksort;
use function strcmp;
use function usort;

use const INF;

/**
 * The mutation-facing query index (D-077, half A): the inverse of the
 * per-test line map — for every covered source line, which tests
 * executed it, ordered fastest-first by the result cache's last
 * duration. A mutation tool mutates a line, then runs its covering
 * tests cheapest-first and stops at the first kill; this index is the
 * "which tests, in what order" answer, assembled from data Crucible
 * already records (D-047 line maps + the result cache's timings).
 *
 * It is the read-only half. The warm mutant re-run API — swap code, run
 * an ordered subset, stop on first fail, return a structured verdict —
 * is deliberately not built here: its shape is owned by a concrete
 * consumer (an Infection adapter, or an in-framework runner), not a
 * hypothetical one.
 */
final readonly class MutationIndex
{
    /**
     * @param array<string, array<int, list<array{id: string, duration: ?float}>>> $index
     *        relative file => line => covering tests, fastest-first
     */
    private function __construct(
        public array $index,
    ) {}

    /**
     * Invert the line map and join the durations. Unknown-duration tests
     * sort last (an unproven cost is not promised cheap); ties break on
     * the id, so the ordering is deterministic and replayable.
     *
     * @param array<string, float> $durations test id => last wall-clock seconds; absent = never timed
     */
    public static function build(TestLineMap $map, array $durations): self
    {
        /** @var array<string, array<int, list<string>>> $byLine */
        $byLine = [];

        foreach ($map->tests as $testId => $files) {
            foreach ($files as $file => $lines) {
                foreach ($lines as $line) {
                    $byLine[$file][$line][] = $testId;
                }
            }
        }

        $index = [];

        foreach ($byLine as $file => $lines) {
            ksort($lines);

            foreach ($lines as $line => $ids) {
                usort($ids, static function (string $a, string $b) use ($durations): int {
                    $order = ($durations[$a] ?? INF) <=> ($durations[$b] ?? INF);

                    return $order !== 0 ? $order : strcmp($a, $b);
                });

                $index[$file][$line] = array_map(
                    static fn(string $id): array => ['id' => $id, 'duration' => $durations[$id] ?? null],
                    $ids,
                );
            }
        }

        ksort($index);

        return new self($index);
    }

    /**
     * The tests that executed $relativeFile:$line, fastest-first — the
     * per-mutant query. Empty when nothing covered the line.
     *
     * @return list<array{id: string, duration: ?float}>
     */
    public function coveringTests(string $relativeFile, int $line): array
    {
        return $this->index[$relativeFile][$line] ?? [];
    }

    /**
     * The whole index, ready to serialize — the interop artifact an
     * external mutation tool loads and queries.
     *
     * @return array<string, array<int, list<array{id: string, duration: ?float}>>>
     */
    public function toArray(): array
    {
        return $this->index;
    }
}
