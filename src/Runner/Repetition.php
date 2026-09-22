<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Test\TestGroup;

/**
 * The spec's --repeat: run every selected test N times. Unlike a retry
 * this is unconditional — a passing test runs again anyway, which is
 * how a flaky one is smoked out — so the repetitions are ordinary runs
 * of the same test, adjacent, exactly as the spec's repeated suite
 * wraps each method rather than the run.
 */
final readonly class Repetition
{
    /**
     * @param list<TestGroup> $groups
     * @param ?int            $times  null, or anything under 2, leaves the plan untouched
     *
     * @return list<TestGroup>
     */
    public static function apply(array $groups, ?int $times): array
    {
        if ($times === null || $times <= 1) {
            return $groups;
        }

        $repeated = [];

        foreach ($groups as $group) {
            $tests = [];

            foreach ($group->tests as $test) {
                for ($run = 0; $run < $times; $run++) {
                    $tests[] = $test;
                }
            }

            $repeated[] = new TestGroup($group->name, $tests, $group->beforeAll, $group->afterAll);
        }

        return $repeated;
    }
}
