<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use Closure;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutationOutcome;
use LucianoPereira\Crucible\Mutation\MutationRunner;
use LucianoPereira\Crucible\Mutation\MutationVerdict;
use LucianoPereira\Crucible\Tests\Mutation\Fixtures\RecordingExecutor;

/**
 * The warm-or-cold routing, verified without spawning: recording doubles
 * report which executor the runner chose for each mutant.
 */
#[CoversClass(MutationRunner::class)]
final class MutationRunnerTest extends TestCase
{
    private function mutant(): Mutant
    {
        return new Mutant('/project/src/Foo.php', 'Foo', 1, 'arithmetic', '<?php');
    }

    /**
     * @return Closure(Mutant): list<non-empty-string>
     */
    private function covers(string ...$ids): callable
    {
        /** @var list<non-empty-string> $ids */
        return static fn(Mutant $mutant): array => $ids;
    }

    /**
     * The verdict must reach the caller as it lands, not at the end.
     *
     * A real run is thousands of mutants over hours, so a report built
     * only at the finish is lost to any interruption. The control is the
     * ordering assertion: a callback fed from the finished report would
     * satisfy the counts and fail this.
     */
    public function testEachVerdictIsHandedOverAsItLands(): void
    {
        $cold    = new RecordingExecutor(canRun: true);
        $seen    = [];
        $mutants = [$this->mutant(), $this->mutant(), $this->mutant()];

        $report = (new MutationRunner($cold, null, $this->covers('t.php::a')))->run(
            $mutants,
            static function (MutationVerdict $verdict, int $done, int $total) use (&$seen, $cold): void {
                // The executor's call count is read INSIDE the callback:
                // it can only equal $done if this ran between executions.
                $seen[] = [$done, $total, $cold->calls];
            },
        );

        self::assertSame([[1, 3, 1], [2, 3, 2], [3, 3, 3]], $seen);
        self::assertSame(3, $report->total(), 'the report is still the whole run');
    }

    public function testAMutantNoTestCoversIsStillHandedOver(): void
    {
        $cold = new RecordingExecutor(canRun: true);
        $seen = [];

        (new MutationRunner($cold, null, $this->covers()))->run(
            [$this->mutant()],
            static function (MutationVerdict $verdict) use (&$seen): void {
                $seen[] = $verdict->outcome;
            },
        );

        self::assertSame([MutationOutcome::NotCovered], $seen, 'an uncovered mutant is a verdict too');
        self::assertSame(0, $cold->calls, 'and it spawns nothing');
    }

    public function testTheRunnerStillWorksWithNoCallback(): void
    {
        $cold = new RecordingExecutor(canRun: true);

        $report = (new MutationRunner($cold, null, $this->covers('t.php::a')))->run([$this->mutant()]);

        self::assertSame(1, $report->total());
    }

    public function testAWarmableMutantRunsWarmNotCold(): void
    {
        $warm = new RecordingExecutor(canRun: true);
        $cold = new RecordingExecutor(canRun: true);

        (new MutationRunner($cold, $warm, $this->covers('t.php::a')))->run([$this->mutant()]);

        self::assertSame(1, $warm->calls, 'A warmable mutant should run warm.');
        self::assertSame(0, $cold->calls);
    }

    public function testAMutantWarmCannotRunFallsBackToCold(): void
    {
        $warm = new RecordingExecutor(canRun: false);
        $cold = new RecordingExecutor(canRun: true);

        (new MutationRunner($cold, $warm, $this->covers('t.php::a')))->run([$this->mutant()]);

        self::assertSame(0, $warm->calls);
        self::assertSame(1, $cold->calls, 'When warm cannot run the mutant, it goes cold.');
    }

    public function testWithNoWarmExecutorEverythingRunsCold(): void
    {
        $cold = new RecordingExecutor(canRun: true);

        (new MutationRunner($cold, null, $this->covers('t.php::a')))->run([$this->mutant()]);

        self::assertSame(1, $cold->calls, 'A platform that cannot fork runs every mutant cold.');
    }

    public function testAnUncoveredMutantSpawnsNothing(): void
    {
        $warm = new RecordingExecutor();
        $cold = new RecordingExecutor();

        $report = (new MutationRunner($cold, $warm, $this->covers()))->run([$this->mutant()]);

        self::assertSame(0, $warm->calls);
        self::assertSame(0, $cold->calls);
        self::assertSame(MutationOutcome::NotCovered, $report->verdicts[0]->outcome);
    }
}
