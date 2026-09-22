<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Flakiness;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Flakiness\OrderDependencyHunter;
use LucianoPereira\Crucible\Flakiness\OrderDependencyReport;
use LucianoPereira\Crucible\Framework\TestCase;

use function is_numeric;
use function is_string;
use function str_contains;

#[CoversClass(OrderDependencyHunter::class)]
#[CoversClass(OrderDependencyReport::class)]
final class OrderDependencyHunterTest extends TestCase
{
    private const string VICTIM = 'tests/VictimTest.php::testClean';

    /**
     * A scripted suite: outcomes per invocation, keyed by the extra
     * arguments the hunter passes.
     *
     * @param array<string, string>              $baseline
     * @param array<int, array<string, string>>  $roundsBySeed
     * @param array<string, string>              $isolated outcome per test name filter
     * @param int<1, max>                        $rounds
     */
    private function hunt(array $baseline, array $roundsBySeed, array $isolated, int $rounds): OrderDependencyReport
    {
        $run = static function (array $extra) use ($baseline, $roundsBySeed, $isolated): array {
            if ($extra === []) {
                return $baseline;
            }

            if (($extra[0] ?? '') === '--filter') {
                $name = $extra[1] ?? '';

                if (!is_string($name) || $name === '') {
                    return [];
                }

                foreach ($isolated as $id => $outcome) {
                    if (str_contains($id, $name)) {
                        return [$id => $outcome];
                    }
                }

                return [];
            }

            // --order-by random --random-order-seed N
            $seedArgument = $extra[3] ?? '0';
            $seed         = is_numeric($seedArgument) ? (int) $seedArgument : 0;

            return $roundsBySeed[$seed] ?? $baseline;
        };

        return (new OrderDependencyHunter($run, static function (string $note): void {}))
            ->hunt($rounds, 100);
    }

    public function testASeedReproducedFailureThatPassesAloneIsOrderDependent(): void
    {
        $green = ['tests/AlphaTest.php::testA' => 'pass', self::VICTIM => 'pass'];
        $red   = ['tests/AlphaTest.php::testA' => 'pass', self::VICTIM => 'fail'];

        $report = $this->hunt(
            baseline: $green,
            roundsBySeed: [101 => $green, 102 => $red],
            // fails at seed 102; replay of 102 fails again
            isolated: [self::VICTIM => 'pass'],
            rounds: 2,
        );

        self::assertSame([self::VICTIM => 102], $report->orderDependent);
        self::assertSame([], $report->nonDeterministic);
        self::assertSame([], $report->brokenAlone);
        $this->assertFalse($report->clean());
    }

    public function testAFailureTheSeedCannotReproduceIsNonDeterministic(): void
    {
        // The scripted round map answers 'fail' the first time seed 102
        // runs and on its replay would answer the same — so to model a
        // non-reproducing failure, the replay consults the same map;
        // instead we script the *isolation* to pass and the seed map to
        // pass on replay by keying the failing outcome to a seed the
        // hunter never replays identically.
        $green = [self::VICTIM => 'pass'];

        $calls = 0;
        $run   = static function (array $extra) use ($green, &$calls): array {
            if ($extra === []) {
                return $green;
            }

            if (($extra[0] ?? '') === '--filter') {
                return [self::VICTIM => 'pass'];
            }

            $calls++;

            // First random round fails; the replay of the same seed passes.
            return $calls === 1 ? [self::VICTIM => 'fail'] : $green;
        };

        $report = (new OrderDependencyHunter($run, static function (string $note): void {}))->hunt(1, 100);

        self::assertSame([], $report->orderDependent);
        self::assertSame([self::VICTIM], $report->nonDeterministic);
    }

    public function testAFailureThatFailsAloneIsBrokenNotFlaky(): void
    {
        $green = [self::VICTIM => 'pass'];
        $red   = [self::VICTIM => 'fail'];

        $report = $this->hunt(baseline: $green, roundsBySeed: [101 => $red], isolated: [self::VICTIM => 'fail'], rounds: 1);

        self::assertSame([], $report->orderDependent);
        self::assertSame([self::VICTIM], $report->brokenAlone);
    }

    public function testBaselineFailuresAreNamedAndExcludedFromTheHunt(): void
    {
        $red = [self::VICTIM => 'fail'];

        $report = $this->hunt(baseline: $red, roundsBySeed: [101 => $red], isolated: [self::VICTIM => 'fail'], rounds: 1);

        self::assertSame([self::VICTIM], $report->baselineFailures);
        self::assertSame([], $report->orderDependent);
        self::assertSame([], $report->brokenAlone);
    }

    public function testACleanSuiteReportsClean(): void
    {
        $green = [self::VICTIM => 'pass'];

        $report = $this->hunt(baseline: $green, roundsBySeed: [], isolated: [], rounds: 2);

        self::assertTrue($report->clean());
    }
}
