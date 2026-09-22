<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Attributes\Large;
use LucianoPereira\Crucible\Attributes\Medium;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_all;
use function array_map;
use function array_reverse;
use function array_sum;
use function array_values;
use function count;
use function max;
use function min;
use function usort;

/**
 * Deterministic test ordering (Rothermel/Elbaum prioritization line).
 * Pure: groups in, groups out; the same inputs — order, seed, history —
 * always produce the same schedule, per the determinism principle.
 *
 * Ordering is two-level (groups, then tests within each group), and
 * every ordering ends with a dependency repair pass so #[Depends]
 * prerequisites always run before their dependents — reordering can
 * never turn a passing suite into skips. --ignore-dependencies drops
 * that pass and leaves the declared positions, which is the only thing
 * the spec's switch turns off: an unmet #[Depends] still skips.
 *
 * `defects` is history-aware beyond the spec: outcomes are scored by
 * defect severity and *recency* (each run further back counts half),
 * so what broke yesterday outranks what flaked last week; ties break
 * toward shorter tests for the fastest time-to-first-failure.
 * `duration` runs fastest-first (the spec's semantics); LPT
 * partitioning of the same cached durations arrives with the worker
 * pool.
 */
final readonly class Scheduler
{
    /**
     * @param bool $resolveDependencies run the repair pass; false is the spec's --ignore-dependencies
     */
    public function __construct(
        private ExecutionOrder $order = ExecutionOrder::Declared,
        private int $seed = 0,
        private ResultCache $history = new ResultCache(),
        private bool $resolveDependencies = true,
    ) {}

    /**
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    public function schedule(array $groups): array
    {
        $groups = match ($this->order) {
            ExecutionOrder::Declared      => $groups,
            ExecutionOrder::Reversed      => $this->reversed($groups),
            ExecutionOrder::Random        => $this->shuffled($groups),
            ExecutionOrder::DefectsFirst  => $this->defectsFirst($groups),
            ExecutionOrder::Duration      => $this->fastestFirst($groups),
            ExecutionOrder::SizeAscending => $this->sizeAscending($groups),
        };

        return $this->resolveDependencies ? array_map($this->repaired(...), $groups) : $groups;
    }

    /**
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    private function reversed(array $groups): array
    {
        return array_map(
            static fn(TestGroup $group): TestGroup => new TestGroup(
                $group->name,
                array_reverse($group->tests),
                $group->beforeAll,
                $group->afterAll,
            ),
            array_reverse($groups),
        );
    }

    /**
     * Seeded and replayable: the same seed reproduces the same order,
     * which is what turns "fails when shuffled" into a bug report.
     *
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    private function shuffled(array $groups): array
    {
        $randomizer = new Randomizer(new Mt19937($this->seed));

        return array_map(
            fn(TestGroup $group): TestGroup => new TestGroup(
                $group->name,
                $this->permuted($randomizer, $group->tests),
                $group->beforeAll,
                $group->afterAll,
            ),
            $this->permuted($randomizer, $groups),
        );
    }

    /**
     * Fisher–Yates over getInt(): the permutation a seed produces is
     * defined by Crucible itself, not by an engine's shuffle internals —
     * replayability is a documented guarantee, so the algorithm behind
     * it must be owned code.
     *
     * @template T
     *
     * @param list<T> $items
     *
     * @return list<T>
     */
    private function permuted(Randomizer $randomizer, array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $randomizer->getInt(0, $i);

            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return array_values($items);
    }

    /**
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    private function defectsFirst(array $groups): array
    {
        $score    = fn(TestDefinition $test): float => $this->defectScore($test);
        $duration = fn(TestDefinition $test): float => $this->history->duration($test->id->toString()) ?? 0.0;

        $groups = $this->sortedGroups($groups, static function (TestGroup $a, TestGroup $b) use ($score): int {
            $best = static fn(TestGroup $group): float => max([0.0, ...array_map($score, $group->tests)]);

            return $best($b) <=> $best($a);
        });

        return $this->sortedTests($groups, static fn(TestDefinition $a, TestDefinition $b): int => [$score($b), $duration($a)] <=> [$score($a), $duration($b)]);
    }

    /**
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    private function fastestFirst(array $groups): array
    {
        $duration = fn(TestDefinition $test): float => $this->history->duration($test->id->toString()) ?? 0.0;

        $groups = $this->sortedGroups($groups, static function (TestGroup $a, TestGroup $b) use ($duration): int {
            $total = static fn(TestGroup $group): float => array_sum(array_map($duration, $group->tests));

            return $total($a) <=> $total($b);
        });

        return $this->sortedTests($groups, static fn(TestDefinition $a, TestDefinition $b): int => $duration($a) <=> $duration($b));
    }

    /**
     * @param list<TestGroup> $groups
     *
     * @return list<TestGroup>
     */
    private function sizeAscending(array $groups): array
    {
        $weight = $this->sizeWeight(...);

        $groups = $this->sortedGroups($groups, static function (TestGroup $a, TestGroup $b) use ($weight): int {
            $smallest = static fn(TestGroup $group): int => min([4, ...array_map($weight, $group->tests)]);

            return $smallest($a) <=> $smallest($b);
        });

        return $this->sortedTests($groups, static fn(TestDefinition $a, TestDefinition $b): int => $weight($a) <=> $weight($b));
    }

    /**
     * Defect severity, recency-weighted: each run further back in the
     * history counts half as much as the one before it.
     */
    private function defectScore(TestDefinition $test): float
    {
        $score  = 0.0;
        $factor = 1.0;

        foreach ($this->history->outcomes($test->id->toString()) as $outcome) {
            $score += $factor * match ($outcome) {
                Outcome::Errored    => 6.0,
                Outcome::Failed     => 5.0,
                Outcome::Incomplete => 3.0,
                Outcome::Risky      => 2.0,
                Outcome::Skipped    => 1.0,
                Outcome::Passed     => 0.0,
            };
            $factor /= 2.0;
        }

        return $score;
    }

    private function sizeWeight(TestDefinition $test): int
    {
        return match (true) {
            $test->metadata->has(Small::class)  => 1,
            $test->metadata->has(Medium::class) => 2,
            $test->metadata->has(Large::class)  => 3,
            default                             => 4,
        };
    }

    /**
     * @param list<TestGroup>                       $groups
     * @param callable(TestGroup, TestGroup): int   $comparator
     *
     * @return list<TestGroup>
     */
    private function sortedGroups(array $groups, callable $comparator): array
    {
        usort($groups, $comparator);

        return $groups;
    }

    /**
     * @param list<TestGroup>                                   $groups
     * @param callable(TestDefinition, TestDefinition): int     $comparator
     *
     * @return list<TestGroup>
     */
    private function sortedTests(array $groups, callable $comparator): array
    {
        return array_map(
            static function (TestGroup $group) use ($comparator): TestGroup {
                $tests = $group->tests;
                usort($tests, $comparator);

                return new TestGroup($group->name, $tests, $group->beforeAll, $group->afterAll);
            },
            $groups,
        );
    }

    /**
     * Keeps #[Depends] prerequisites ahead of their dependents by
     * *deferring* dependents — the spec's observable behavior: a test
     * whose dependencies have not run yet moves later, everything else
     * keeps its scheduled position. Dataset rows share a name and are
     * emitted as one block. A cyclic dependency can never become
     * ready, so its block is appended in scheduled order and left to
     * the runner's skip semantics.
     */
    private function repaired(TestGroup $group): TestGroup
    {
        /** @var array<string, list<TestDefinition>> $blocks name → dataset rows, in scheduled order */
        $blocks = [];

        foreach ($group->tests as $test) {
            $blocks[$test->id->name][] = $test;
        }

        /** @var array<string, true> $emitted */
        $emitted = [];

        /** @var list<TestDefinition> $ordered */
        $ordered = [];

        do {
            $progressed = false;

            foreach ($blocks as $name => $block) {
                if (isset($emitted[$name])) {
                    continue;
                }

                $ready = array_all(
                    $block[0]->dependencies,
                    static fn(string $dependency): bool => isset($emitted[$dependency]) || !isset($blocks[$dependency]),
                );

                if (!$ready) {
                    continue;
                }

                $emitted[$name] = true;
                $progressed     = true;

                foreach ($block as $test) {
                    $ordered[] = $test;
                }
            }
        } while ($progressed);

        // Whatever never became ready is cyclic: emit in scheduled order.
        foreach ($blocks as $name => $block) {
            if (!isset($emitted[$name])) {
                foreach ($block as $test) {
                    $ordered[] = $test;
                }
            }
        }

        return new TestGroup($group->name, $ordered, $group->beforeAll, $group->afterAll);
    }
}
