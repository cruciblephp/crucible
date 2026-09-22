<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Compat\Migration;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\Migration\OutcomeDiff;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(OutcomeDiff::class)]
final class OutcomeDiffTest extends TestCase
{
    public function testFlagsOnlyOraclePassCrucibleFailOrError(): void
    {
        $oracle = [
            'A::test1' => 'pass',
            'A::test2' => 'pass',
            'A::test3' => 'pass',
            'A::test4' => 'fail',
            'A::test5' => 'pass',
        ];

        $crucible = [
            'A::test1' => 'pass',
            'A::test2' => 'fail',
            'A::test3' => 'error',
            'A::test4' => 'fail',
            // test5 missing entirely from the Crucible side.
        ];

        $this->assertSame(['A::test2', 'A::test3'], OutcomeDiff::driftedToFailure($oracle, $crucible));
    }

    public function testNoDriftWhenEverythingAgrees(): void
    {
        $oracle   = ['A::test1' => 'pass', 'A::test2' => 'skip'];
        $crucible = ['A::test1' => 'pass', 'A::test2' => 'skip'];

        $this->assertSame([], OutcomeDiff::driftedToFailure($oracle, $crucible));
    }

    public function testOracleNonPassOutcomesAreNeverCandidatesRegardlessOfCrucibleSide(): void
    {
        $oracle   = ['A::test1' => 'skip', 'A::test2' => 'risky', 'A::test3' => 'fail'];
        $crucible = ['A::test1' => 'fail', 'A::test2' => 'error', 'A::test3' => 'fail'];

        $this->assertSame([], OutcomeDiff::driftedToFailure($oracle, $crucible));
    }
    /**
     * The blind spot driftedToFailure has by design: a test the oracle
     * passed and Crucible produced no outcome for is absent, not
     * agreed with, and only this question can see it.
     */
    public function testATestTheOracleRanAndCrucibleDidNotIsReportedMissing(): void
    {
        $oracle = [
            'A::test1' => 'pass',
            'A::test2' => 'pass',
            'A::test3' => 'fail',
        ];

        $crucible = ['A::test1' => 'pass'];

        $this->assertSame(['A::test2'], OutcomeDiff::missingFromCrucible($oracle, $crucible));
        $this->assertSame([], OutcomeDiff::driftedToFailure($oracle, $crucible));
    }

    /**
     * The case that made this worth asking: a suite Crucible never
     * discovered scores zero drift, which reads as parity and is the
     * most confident wrong answer the tool can give.
     */
    public function testAnEmptyCrucibleRunIsEveryOraclePassMissingRatherThanNoDrift(): void
    {
        $oracle = ['A::test1' => 'pass', 'A::test2' => 'pass', 'A::test3' => 'skip'];

        $this->assertSame([], OutcomeDiff::driftedToFailure($oracle, []));
        $this->assertSame(['A::test1', 'A::test2'], OutcomeDiff::missingFromCrucible($oracle, []));
    }

    /**
     * Only what the oracle actually passed is owed an outcome; a test
     * it skipped says nothing about Crucible either way.
     */
    public function testATestTheOracleDidNotPassIsNotOwedAnOutcome(): void
    {
        $oracle = ['A::test1' => 'skip', 'A::test2' => 'fail', 'A::test3' => 'error'];

        $this->assertSame([], OutcomeDiff::missingFromCrucible($oracle, []));
    }

    public function testNothingIsMissingWhenCrucibleAnsweredEveryOraclePass(): void
    {
        $oracle   = ['A::test1' => 'pass', 'A::test2' => 'pass'];
        $crucible = ['A::test1' => 'pass', 'A::test2' => 'fail'];

        $this->assertSame([], OutcomeDiff::missingFromCrucible($oracle, $crucible));
    }

}
