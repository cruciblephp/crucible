<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Renderers\Support;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Renderers\Support\FailureLocation;
use LucianoPereira\Crucible\Test\TestId;

#[CoversClass(FailureLocation::class)]
final class FailureLocationTest extends TestCase
{
    public function testPicksTheFrameMatchingTheTestsOwnDeclaringFile(): void
    {
        $test = new TestFinished(
            new TestId('tests/DemoTest.php', 'testFailsOnPurpose'),
            Outcome::Failed,
            0.001,
            new Failure('Failed asserting that 4 is identical to 5.', trace: [
                new Frame('/vendor/crucible/src/Assert/Assert.php', 86, 'evaluate'),
                new Frame('/vendor/crucible/src/Assert/Assert.php', 138, 'assertThat'),
                new Frame('/project/tests/DemoTest.php', 12, 'assertSame'),
                new Frame('/vendor/crucible/src/Runner/TestRunner.php', 400, 'runTest'),
            ]),
        );

        $frame = FailureLocation::of($test);

        $this->assertNotNull($frame);
        $this->assertSame('/project/tests/DemoTest.php', $frame->file);
        $this->assertSame(12, $frame->line);
    }

    public function testFallsBackToTheInnermostFrameWhenNoneMatchesTheTestFile(): void
    {
        // An Errored test whose fatal originated in source code under
        // test, not the test file itself — still worth a location.
        $test = new TestFinished(
            new TestId('tests/DemoTest.php', 'testErrorsOnPurpose'),
            Outcome::Errored,
            0.001,
            new Failure('Division by zero', trace: [
                new Frame('/project/src/Calculator.php', 42, 'divide'),
                new Frame('/vendor/crucible/src/Runner/TestRunner.php', 400, 'runTest'),
            ]),
        );

        $frame = FailureLocation::of($test);

        $this->assertNotNull($frame);
        $this->assertSame('/project/src/Calculator.php', $frame->file);
        $this->assertSame(42, $frame->line);
    }

    public function testReturnsNullWhenThereIsNoFailureAtAll(): void
    {
        $test = new TestFinished(
            new TestId('tests/DemoTest.php', 'testSkipsOnPurpose'),
            Outcome::Skipped,
            0.001,
            reason: 'demo skip',
        );

        $this->assertNull(FailureLocation::of($test));
    }
}
