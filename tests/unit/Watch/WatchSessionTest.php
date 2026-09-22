<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Watch;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ImpactRule;
use LucianoPereira\Crucible\Watch\WatchRun;
use LucianoPereira\Crucible\Watch\WatchSession;

#[CoversClass(WatchSession::class)]
#[CoversClass(WatchRun::class)]
#[CoversClass(ImpactRule::class)]
final class WatchSessionTest extends TestCase
{
    public function testAGreenSessionRunsTheAffectedFiles(): void
    {
        $run = (new WatchSession())->onChange(['src/A.php'], false);

        self::assertSame(['src/A.php'], $run->related);
    }

    public function testDeletionsWidenToTheFullSuite(): void
    {
        self::assertTrue((new WatchSession())->onChange([], true)->isFull());
    }

    public function testNonPhpChangesWidenToTheFullSuite(): void
    {
        self::assertTrue((new WatchSession())->onChange(['tests/fixture.json'], false)->isFull());
    }

    public function testAFileADeclaredRuleNamesRunsRelatedNotFull(): void
    {
        // Without the rule this widens to everything; with it, the child's
        // ImpactSelection turns the path into the groups it puts in doubt.
        $session = new WatchSession([], [new ImpactRule('resources/js', ['browser'])], '/project');
        $run     = $session->onChange(['/project/resources/js/Cart.vue'], false);

        self::assertFalse($run->isFull());
        self::assertSame(['/project/resources/js/Cart.vue'], $run->related);
    }

    public function testAFileNoRuleNamesStillWidens(): void
    {
        $session = new WatchSession([], [new ImpactRule('resources/js', ['browser'])], '/project');

        self::assertTrue($session->onChange(['/project/docs/guide.md'], false)->isFull());
    }

    public function testARuleDoesNotRescueADeletion(): void
    {
        // A deleted file's dependents are invisible to every graph, rules
        // included — the safety direction outranks precision.
        $session = new WatchSession([], [new ImpactRule('resources/js', ['browser'])], '/project');

        self::assertTrue($session->onChange(['/project/resources/js/Cart.vue'], true)->isFull());
    }

    public function testAJsChangeUnderAVitestSuiteRunsRelatedNotFull(): void
    {
        $session = new WatchSession(['/proj/resources/js']);

        $run = $session->onChange(['/proj/resources/js/components/Cart.vue'], false);

        self::assertFalse($run->isFull());
        self::assertSame(['/proj/resources/js/components/Cart.vue'], $run->related);
    }

    public function testANonPhpChangeOutsideEveryVitestSuiteStillWidensToFull(): void
    {
        $session = new WatchSession(['/proj/resources/js']);

        self::assertTrue($session->onChange(['/proj/public/build/app.css'], false)->isFull());
    }

    public function testTheFailedSetIsStickyAcrossChanges(): void
    {
        $session = new WatchSession();

        // A run fails in two files…
        $session->onResult(WatchRun::full('initial'), ['tests/RedTest.php', 'tests/AlsoRedTest.php']);

        self::assertTrue($session->isRed());

        // …then an edit somewhere else still carries them along.
        $run = $session->onChange(['src/Elsewhere.php'], false);

        self::assertSame(
            ['src/Elsewhere.php', 'tests/RedTest.php', 'tests/AlsoRedTest.php'],
            $run->related,
        );
    }

    public function testAGreenPartialRunAfterRedEarnsOneFullConfirmation(): void
    {
        $session = new WatchSession();

        $session->onResult(WatchRun::full('initial'), ['tests/RedTest.php']);

        $partial  = $session->onChange(['tests/RedTest.php'], false);
        $followUp = $session->onResult($partial, []);

        self::assertInstanceOf(WatchRun::class, $followUp, 'Expected the confirming full run.');

        self::assertTrue($followUp->isFull());

        // The confirmation coming back green ends the cycle quietly.
        self::assertNull($session->onResult($followUp, []));
        self::assertFalse($session->isRed());
    }

    public function testAGreenPartialRunWithoutPriorFailuresNeedsNoConfirmation(): void
    {
        $session = new WatchSession();

        $session->onResult(WatchRun::full('initial'), []);

        self::assertNull($session->onResult($session->onChange(['src/A.php'], false), []));
    }

    public function testAStillRedRunWaitsInsteadOfLooping(): void
    {
        $session = new WatchSession();

        $session->onResult(WatchRun::full('initial'), ['tests/RedTest.php']);

        self::assertNull($session->onResult($session->onChange(['src/A.php'], false), ['tests/RedTest.php']));
        self::assertTrue($session->isRed());
    }

    public function testTheFailedOnlyRunIsNullWhenGreen(): void
    {
        self::assertNull((new WatchSession())->failedRun());
    }

    public function testTheFailedOnlyRunTargetsExactlyTheFailedFiles(): void
    {
        $session = new WatchSession();

        $session->onResult(WatchRun::full('initial'), ['tests/RedTest.php']);

        $run = $session->failedRun();

        self::assertInstanceOf(WatchRun::class, $run, 'Expected a failed-only run.');

        self::assertSame(['tests/RedTest.php'], $run->related);
    }
}
