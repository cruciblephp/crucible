<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Event;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;

#[CoversClass(TestStarted::class)]
#[CoversClass(TestFinished::class)]
#[CoversClass(Failure::class)]
#[CoversClass(RunFinished::class)]
#[CoversClass(RunSummary::class)]
final class EventPayloadTest extends TestCase
{
    public function testOptionalFieldsAreOmittedNotNull(): void
    {
        $test = new TestId('tests/T.php', 't');

        $started  = new TestStarted($test);
        $finished = new TestFinished($test, Outcome::Passed, 0.1);

        $this->assertSame(['id'], array_keys($started->payload()));
        $this->assertSame(['id', 'outcome', 'duration'], array_keys($finished->payload()));
    }

    public function testRetryAttemptAndSkipReasonAppearWhenSet(): void
    {
        $test = new TestId('tests/T.php', 't');

        $retried = new TestStarted($test, attempt: 2);
        $skipped = new TestFinished($test, Outcome::Skipped, 0.0, reason: 'requires ext-intl');

        $this->assertSame(2, $retried->payload()['attempt']);
        $this->assertSame('requires ext-intl', $skipped->payload()['reason']);
    }

    public function testBlockedAppearsOnlyWhenSet(): void
    {
        $test = new TestId('tests/T.php', 't');

        $ordinary = new TestFinished($test, Outcome::Skipped, 0.0, reason: 'not ready');
        $blocked  = new TestFinished($test, Outcome::Skipped, 0.0, reason: 'PHP extension xdebug is missing.', blocked: true);

        $this->assertArrayNotHasKey('blocked', $ordinary->payload());
        $this->assertTrue($blocked->payload()['blocked']);
    }

    public function testUntestedIsASubsetOfSkippedNotAnExtraOutcome(): void
    {
        // Two skips, one of them blocked: the outcome partition still
        // counts two skips, and untested rides alongside without
        // inflating total() or the outcome map (D-075).
        $summary = new RunSummary(passed: 3, skipped: 2, untested: 1);

        $this->assertSame(5, $summary->total());
        $this->assertSame(1, $summary->untested);
        $this->assertSame(2, $summary->toArray()['skip']);
        $this->assertArrayNotHasKey('untested', $summary->toArray());
    }

    public function testUntestedRidesRunFinishedAsAnAdditiveField(): void
    {
        $bare     = new RunFinished(new RunSummary(passed: 2, skipped: 1), 1.0);
        $untested = new RunFinished(new RunSummary(passed: 2, skipped: 2, untested: 1), 1.0);

        // Omitted when zero; its own additive field when present (the
        // counts map stays outcome-only — proven separately via
        // toArray). D-075, the issues precedent.
        $this->assertArrayNotHasKey('untested', $bare->payload());
        $this->assertSame(1, $untested->payload()['untested']);
    }

    public function testRunSummaryPlusSumsEveryFieldForTheVitestFold(): void
    {
        $php = new RunSummary(passed: 3, failed: 1, skipped: 2, deprecations: 4, untested: 1);
        $js  = new RunSummary(passed: 5, failed: 2, incomplete: 1);

        $merged = $php->plus($js);

        $this->assertSame(8, $merged->passed);
        $this->assertSame(3, $merged->failed);
        $this->assertSame(1, $merged->incomplete);
        $this->assertSame(4, $merged->deprecations);
        $this->assertSame(1, $merged->untested);
        $this->assertSame(14, $merged->total());
    }

    public function testFailurePayloadOmitsAbsentParts(): void
    {
        $bare = new Failure('boom');

        $this->assertSame(['message' => 'boom'], $bare->toArray());
    }

    public function testRunSummaryCountsAndVerdict(): void
    {
        $green = new RunSummary(passed: 3, skipped: 1);
        $red   = new RunSummary(passed: 3, errored: 1);

        $this->assertSame(4, $green->total());
        $this->assertTrue($green->successful());
        $this->assertFalse($red->successful());
        $this->assertSame(
            ['pass' => 3, 'fail' => 0, 'error' => 0, 'skip' => 1, 'incomplete' => 0, 'risky' => 0],
            $green->toArray(),
        );
    }
}
