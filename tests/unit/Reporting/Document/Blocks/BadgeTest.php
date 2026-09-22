<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Blocks\Badge;
use LucianoPereira\Crucible\Reporting\Document\Tone;

/**
 * The one canonical verdict rule (D-091's addendum), previously
 * reimplemented separately in `ConsoleReporter` and `PdfWriter`
 * (`MarkdownWriter` never had the FLAKY case at all).
 */
#[CoversClass(Badge::class)]
final class BadgeTest extends TestCase
{
    public function testOkWhenNothingFailedAndNothingWasFlaky(): void
    {
        $badge = Badge::forRun(new RunSummary(passed: 3), false);

        $this->assertSame('OK', $badge->text);
        $this->assertSame(Tone::Success, $badge->tone);
    }

    public function testFlakyWhenAPassedOnlyOnRetryAndNothingFailed(): void
    {
        $badge = Badge::forRun(new RunSummary(passed: 3), true);

        $this->assertSame('FLAKY', $badge->text);
        $this->assertSame(Tone::Caution, $badge->tone);
    }

    public function testFailedWinsOverFlakyWhenBothApply(): void
    {
        $badge = Badge::forRun(new RunSummary(passed: 2, failed: 1), true);

        $this->assertSame('FAILED', $badge->text);
        $this->assertSame(Tone::Danger, $badge->tone);
    }

    public function testErroredAloneIsAlsoFailed(): void
    {
        $badge = Badge::forRun(new RunSummary(passed: 2, errored: 1), false);

        $this->assertSame('FAILED', $badge->text);
        $this->assertSame(Tone::Danger, $badge->tone);
    }

    public function testSkipsAndIncompletesNeverChangeTheVerdict(): void
    {
        $badge = Badge::forRun(new RunSummary(passed: 1, skipped: 2, incomplete: 3, risky: 4), false);

        $this->assertSame('OK', $badge->text);
    }

    public function testSampleReturnsAValidInstance(): void
    {
        $badge = Badge::sample();

        $this->assertSame('OK', $badge->text);
        $this->assertSame(Tone::Success, $badge->tone);
    }
}
