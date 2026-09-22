<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\Outcome;

use function array_map;
use function array_slice;
use function array_unshift;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_dir;
use function is_file;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * The JSON result cache: per-test outcome history and last duration,
 * persisted across runs. This is the data substrate for history-aware
 * scheduling — defects-first ordering, duration ordering, and (with
 * the worker pool) LPT partitioning all read from here.
 *
 * Two departures from the spec's cache file: outcomes are a bounded
 * *history* (most recent first) rather than a single last status —
 * recency-weighted defect scoring needs more than one run of memory —
 * and the format is versioned, so an incompatible file is discarded,
 * never misread. A missing or corrupt cache is an empty cache; the
 * cache can only ever improve scheduling, never fail a run.
 */
final class ResultCache
{
    public const int VERSION = 1;

    /**
     * Runs of memory per test. Enough for recency weighting to
     * distinguish "just broke" from "flaked once last week"; small
     * enough that the file stays trivial.
     */
    public const int HISTORY_LIMIT = 8;

    /**
     * @var array<string, array{outcomes: list<Outcome>, duration: float}>
     */
    private array $tests = [];

    public static function load(string $file): self
    {
        $cache = new self();

        if (!is_file($file)) {
            return $cache;
        }

        $contents = file_get_contents($file);

        if ($contents === false) {
            return $cache;
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || !is_array($decoded['tests'] ?? null)) {
            return $cache;
        }

        foreach ($decoded['tests'] as $id => $entry) {
            if (!is_string($id) || $id === '' || !is_array($entry)) {
                continue;
            }

            $duration = $entry['duration'] ?? null;

            if (!is_float($duration) && !is_int($duration)) {
                continue;
            }

            $outcomes = [];

            foreach (is_array($entry['outcomes'] ?? null) ? $entry['outcomes'] : [] as $value) {
                $outcome = is_string($value) ? Outcome::tryFrom($value) : null;

                if ($outcome instanceof Outcome) {
                    $outcomes[] = $outcome;
                }
            }

            $cache->tests[$id] = [
                'outcomes' => array_slice($outcomes, 0, self::HISTORY_LIMIT),
                'duration' => (float) $duration,
            ];
        }

        return $cache;
    }

    public function persist(string $file): void
    {
        $directory = dirname($file);

        if (!is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        $tests = [];

        foreach ($this->tests as $id => $entry) {
            $tests[$id] = [
                'outcomes' => array_map(static fn(Outcome $outcome): string => $outcome->value, $entry['outcomes']),
                'duration' => $entry['duration'],
            ];
        }

        file_put_contents($file, json_encode(
            ['version' => self::VERSION, 'tests' => $tests],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * @param non-empty-string $id the TestId string form
     */
    public function record(string $id, Outcome $outcome, float $duration): void
    {
        $outcomes = $this->tests[$id]['outcomes'] ?? [];

        array_unshift($outcomes, $outcome);

        $this->tests[$id] = [
            'outcomes' => array_slice($outcomes, 0, self::HISTORY_LIMIT),
            'duration' => $duration,
        ];
    }

    /**
     * Most recent first.
     *
     * @param non-empty-string $id
     *
     * @return list<Outcome>
     */
    public function outcomes(string $id): array
    {
        return $this->tests[$id]['outcomes'] ?? [];
    }

    /**
     * Last observed wall-clock seconds; null for a test never seen.
     *
     * @param non-empty-string $id
     */
    public function duration(string $id): ?float
    {
        return isset($this->tests[$id]) ? $this->tests[$id]['duration'] : null;
    }
}
