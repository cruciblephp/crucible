<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

/**
 * The one comparison `compat-check` cares about: a test the real
 * PHPUnit oracle passes, that Crucible does not — the signature of a
 * test asserting PHPUnit's own internals rather than real behavior,
 * as opposed to a genuine Crucible bug (which would show up as errors
 * across many unrelated tests, not one drifted assertion).
 */
final readonly class OutcomeDiff
{
    /**
     * @param array<string, string> $oracle
     * @param array<string, string> $crucible
     *
     * @return list<non-empty-string> "FQCN::method#dataset" keys
     */
    public static function driftedToFailure(array $oracle, array $crucible): array
    {
        $drifted = [];

        foreach ($oracle as $key => $outcome) {
            if ($outcome !== 'pass') {
                continue;
            }

            $theirs = $crucible[$key] ?? null;

            if ($theirs === 'fail' || $theirs === 'error') {
                /** @var non-empty-string $key */
                $drifted[] = $key;
            }
        }

        return $drifted;
    }

    /**
     * Tests the oracle passed that Crucible produced no outcome for at
     * all. Not the same question as drifting to a failure, and asking
     * only that one scores an absence as agreement: a suite Crucible
     * never discovered reports zero drift and exits 0, which is the
     * most confident wrong answer the tool can give.
     *
     * @param array<string, string> $oracle
     * @param array<string, string> $crucible
     *
     * @return list<non-empty-string> "FQCN::method#dataset" keys
     */
    public static function missingFromCrucible(array $oracle, array $crucible): array
    {
        $missing = [];

        foreach ($oracle as $key => $outcome) {
            if ($outcome !== 'pass' || $key === '' || isset($crucible[$key])) {
                continue;
            }

            $missing[] = $key;
        }

        return $missing;
    }
}
