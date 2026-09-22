<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use LucianoPereira\Crucible\Test\TestId;

/**
 * The ambient bridge between the runner and Property::check (D-040):
 * the runner opens a context around each test attempt (the test's
 * identity plus the known-failing sequences loaded from the failure
 * database); each check() inside the test claims the next property
 * key — a per-attempt counter, so retries replay identically — and
 * receives the stored sequences for it. Static because a test body
 * cannot be handed parameters; single-threaded per process by the
 * runner's design, and workers are separate processes.
 *
 * check() works without a context too (direct engine use) — it just
 * runs without a database.
 */
final class PropertyContext
{
    private static ?TestId $test = null;

    /** @var array<string, list<list<int>>> */
    private static array $known = [];

    private static int $counter = 0;

    /** @var list<non-empty-string> */
    private static array $clean = [];

    /** @var list<non-empty-string> */
    private static array $lastClean = [];

    /**
     * @param array<string, list<list<int>>> $known failure-database entries, keyed test-id#property N
     */
    public static function begin(TestId $test, array $known): void
    {
        self::$test    = $test;
        self::$known   = $known;
        self::$counter = 0;
        self::$clean   = [];
    }

    public static function end(): void
    {
        self::$lastClean = self::$clean;

        self::$test    = null;
        self::$known   = [];
        self::$counter = 0;
        self::$clean   = [];
    }

    /**
     * Property::check reports that every stored sequence for the key
     * replayed without falsifying (and none was inert) — the per-key
     * resolution signal pruning needs (D-071).
     *
     * @param non-empty-string $key
     */
    public static function confirmClean(string $key): void
    {
        self::$clean[] = $key;
    }

    /**
     * The clean keys of the just-ended attempt. Stashed at end() by
     * the ending context (the Snapshots::recorded() pattern), so a
     * nested harness run cannot leak its keys onto the outer attempt.
     *
     * @return list<non-empty-string>
     */
    public static function replayedClean(): array
    {
        return self::$lastClean;
    }

    /**
     * @return ?array{key: non-empty-string, sequences: list<list<int>>}
     */
    public static function claim(): ?array
    {
        if (!self::$test instanceof TestId) {
            return null;
        }

        $key = self::$test->toString() . '#property ' . self::$counter++;

        return ['key' => $key, 'sequences' => self::$known[$key] ?? []];
    }
}
