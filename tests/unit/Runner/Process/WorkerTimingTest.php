<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\RunInSeparateProcess;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\Supervisor;
use LucianoPereira\Crucible\Runner\TimingOverhead;

use function extension_loaded;
use function ini_get;
use function str_contains;

/**
 * The supervisor chooses a worker's xdebug mode, and this test runs
 * inside one — so it reads the child's own ini rather than asserting
 * what the parent put on the command line. Without it the flag could
 * be dropped, misspelled or ignored and every gate would stay green.
 */
#[CoversClass(Supervisor::class)]
final class WorkerTimingTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testAWorkerDoesNotTimeItsTestsThroughXdebug(): void
    {
        if (!extension_loaded('xdebug')) {
            $this->assertSame('', TimingOverhead::mode());

            return;
        }

        if (str_contains((string) ini_get('xdebug.mode'), 'debug')) {
            $this->markTestSkipped('xdebug is in debug mode, so a clean child mode is not available.');
        }

        // Coverage is the one reason a worker keeps the call hook: the
        // supervisor recreates the parent's driver in the child (D-041),
        // and a coverage run's durations are inflated by design.
        $mode = TimingOverhead::mode();

        $this->assertTrue(
            !TimingOverhead::inflating($mode) || str_contains($mode, 'coverage'),
            'A non-coverage worker measures xdebug, not the test: its mode is "' . $mode . '".',
        );
    }
}
