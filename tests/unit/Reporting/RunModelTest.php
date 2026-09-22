<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\RunModel;
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;

#[CoversClass(RunModel::class)]
final class RunModelTest extends TestCase
{
    public function testGroupsFinishedTestsByFileInFirstSeenOrder(): void
    {
        $a = new TestFinished(new TestId('tests/unit/ATest.php', 'testOne'), Outcome::Passed, 0.01);
        $b = new TestFinished(new TestId('tests/unit/BTest.php', 'testTwo'), Outcome::Passed, 0.02);
        $c = new TestFinished(new TestId('tests/unit/ATest.php', 'testThree'), Outcome::Passed, 0.03);

        $model = new RunModel([$a, $b, $c]);

        $this->assertSame(
            ['tests/unit/ATest.php', 'tests/unit/BTest.php'],
            array_keys($model->sections),
        );
        $this->assertSame([$a, $c], $model->sections['tests/unit/ATest.php']);
        $this->assertSame([$b], $model->sections['tests/unit/BTest.php']);
    }

    public function testPassedTestsAreNeitherProblemsNorUntested(): void
    {
        $passed = new TestFinished(new TestId('tests/unit/ATest.php', 'testOne'), Outcome::Passed, 0.01);

        $model = new RunModel([$passed]);

        $this->assertSame([], $model->problems);
        $this->assertSame([], $model->untested);
    }

    public function testNonPassedUnblockedTestsAreProblems(): void
    {
        $failed  = new TestFinished(new TestId('tests/unit/ATest.php', 'testFailed'), Outcome::Failed, 0.01);
        $errored = new TestFinished(new TestId('tests/unit/ATest.php', 'testErrored'), Outcome::Errored, 0.01);
        $risky   = new TestFinished(new TestId('tests/unit/ATest.php', 'testRisky'), Outcome::Risky, 0.01);
        $skipped = new TestFinished(new TestId('tests/unit/ATest.php', 'testSkipped'), Outcome::Skipped, 0.0);

        $model = new RunModel([$failed, $errored, $risky, $skipped]);

        $this->assertSame([$failed, $errored, $risky, $skipped], $model->problems);
        $this->assertSame([], $model->untested);
    }

    public function testBlockedTestsAreUntestedNotProblemsRegardlessOfOutcome(): void
    {
        $blocked = new TestFinished(
            new TestId('tests/unit/ATest.php', 'testBlocked'),
            Outcome::Skipped,
            0.0,
            reason: 'ext-redis is not loaded.',
            blocked: true,
        );

        $model = new RunModel([$blocked]);

        $this->assertSame([], $model->problems);
        $this->assertSame([$blocked], $model->untested);
    }

    public function testUntestedGroupsByReasonInFirstSeenOrder(): void
    {
        $noRedis1 = new TestFinished(
            new TestId('tests/unit/ATest.php', 'testOne'),
            Outcome::Skipped,
            0.0,
            reason: 'Redis is not available.',
            blocked: true,
        );
        $noDb = new TestFinished(
            new TestId('tests/unit/BTest.php', 'testTwo'),
            Outcome::Skipped,
            0.0,
            reason: 'Database is not available.',
            blocked: true,
        );
        $noRedis2 = new TestFinished(
            new TestId('tests/unit/ATest.php', 'testThree'),
            Outcome::Skipped,
            0.0,
            reason: 'Redis is not available.',
            blocked: true,
        );

        $model = new RunModel([$noRedis1, $noDb, $noRedis2]);

        $this->assertSame(['Redis is not available.', 'Database is not available.'], array_keys($model->untestedByReason));
        $this->assertSame([$noRedis1, $noRedis2], $model->untestedByReason['Redis is not available.']);
        $this->assertSame([$noDb], $model->untestedByReason['Database is not available.']);
    }

    public function testUntestedWithNoReasonFallsBackToACouldNotRunLabel(): void
    {
        $blocked = new TestFinished(
            new TestId('tests/unit/ATest.php', 'testBlocked'),
            Outcome::Skipped,
            0.0,
            blocked: true,
        );

        $model = new RunModel([$blocked]);

        $this->assertSame(['Could not run.'], array_keys($model->untestedByReason));
        $this->assertSame([$blocked], $model->untestedByReason['Could not run.']);
    }
}
