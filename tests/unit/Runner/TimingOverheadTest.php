<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\TimingOverhead;

use function extension_loaded;
use function ini_get;

#[CoversClass(TimingOverhead::class)]
final class TimingOverheadTest extends TestCase
{
    public function testOffIsTheOnlyModeThatLeavesADurationAlone(): void
    {
        $this->assertFalse(TimingOverhead::inflating('off'));

        // Every other mode installs the call hook, coverage included:
        // a coverage run's durations are inflated too.
        $this->assertTrue(TimingOverhead::inflating('develop'));
        $this->assertTrue(TimingOverhead::inflating('coverage'));
        $this->assertTrue(TimingOverhead::inflating('debug'));
        $this->assertTrue(TimingOverhead::inflating('develop,coverage'));
    }

    public function testAnEmptyModeIsTheCleanAnswerAndNotAMissingOne(): void
    {
        // ✓ Measured: -d xdebug.mode=off reports '' from ini_get, never
        // the literal 'off'. An empty mode therefore means nothing is
        // hooking calls — whether the extension is absent or disabled.
        $this->assertFalse(TimingOverhead::inflating(''));
        $this->assertNull(TimingOverhead::notice(''));
    }

    public function testTheNoticeNamesTheModeAndTheWayOut(): void
    {
        $notice = TimingOverhead::notice('develop');

        $this->assertIsString($notice);
        $this->assertStringContainsString('develop', $notice);
        $this->assertStringContainsString('-d xdebug.mode=off', $notice);

        $this->assertNull(TimingOverhead::notice('off'));
    }

    public function testTheModeIsReadFromTheIniTheExtensionRunsWith(): void
    {
        if (!extension_loaded('xdebug')) {
            $this->assertSame('', TimingOverhead::mode());
            $this->assertFalse(TimingOverhead::inflating());

            return;
        }

        // Not XDEBUG_MODE: the env var seeds startup, and what the
        // extension is running with afterwards is the ini value.
        $ini = ini_get('xdebug.mode');

        $this->assertSame($ini === false ? '' : $ini, TimingOverhead::mode());
    }

    public function testTheDefaultAnswerAndTheAskedOneAgree(): void
    {
        $this->assertSame(TimingOverhead::inflating(TimingOverhead::mode()), TimingOverhead::inflating());
        $this->assertSame(TimingOverhead::notice(TimingOverhead::mode()), TimingOverhead::notice());
    }
}
