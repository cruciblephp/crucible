<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;

use function hexdec;
use function preg_match;
use function substr;

/**
 * CI sharding (nextest's partitioning): `--shard 3/8` runs the third
 * of eight hash-disjoint slices of the suite. Membership comes from
 * the TestId's stable hash — which is the point of that hash (D-008):
 * adding or removing tests never moves *other* tests between shards,
 * so shard caches and shard timings stay meaningful across commits.
 */
final readonly class Shard
{
    /**
     * @param positive-int $index 1-based
     * @param positive-int $total
     */
    public function __construct(
        public int $index,
        public int $total,
    ) {}

    /**
     * Parses the CLI's "M/N" form; null when malformed or M > N.
     */
    public static function fromString(string $shard): ?self
    {
        if (preg_match('/^([1-9]\d*)\/([1-9]\d*)$/', $shard, $match) !== 1) {
            return null;
        }

        $index = (int) $match[1];
        $total = (int) $match[2];

        if ($index < 1 || $total < 1 || $index > $total) {
            return null;
        }

        return new self($index, $total);
    }

    /**
     * Keeps only this shard's tests; groups that end up empty
     * disappear. Dataset rows shard with their declared name (they
     * hash individually but a split provider row shares its worker
     * anyway, so the simpler per-id bucket is used — stable either way).
     *
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    public function apply(array $groups): array
    {
        if ($this->total === 1) {
            return $groups;
        }

        $selected = [];

        foreach ($groups as $group) {
            $tests = [];

            foreach ($group->tests as $test) {
                if ($this->contains($test)) {
                    $tests[] = $test;
                }
            }

            if ($tests !== []) {
                $selected[] = new TestGroup($group->name, $tests, $group->beforeAll, $group->afterAll);
            }
        }

        return $selected;
    }

    private function contains(TestDefinition $test): bool
    {
        $bucket = (int) hexdec(substr($test->id->hash(), 0, 8)) % $this->total;

        return $bucket + 1 === $this->index;
    }
}
