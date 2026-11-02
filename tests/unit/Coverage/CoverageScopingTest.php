<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\CLI\PostRunReport;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoveragePercent;
use LucianoPereira\Crucible\Coverage\TextReport;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_keys;
use function range;

/**
 * A score floored, never rounded up; the lines a file misses; and a
 * report narrowed to a target, ported from laravel-module-test's
 * ScopedCoverageReport (MIT) and held to the cases its README states.
 */
#[CoversClass(CoveragePercent::class)]
#[CoversClass(TextReport::class)]
#[CoversClass(CoverageData::class)]
final class CoverageScopingTest extends TestCase
{
    public function testAScoreIsFlooredAtTwoPlaces(): void
    {
        self::assertSame(42.85, CoveragePercent::of(3, 7), 'PHPUnit prints 42.85% too');
        self::assertSame(99.99, CoveragePercent::of(99996, 100000));
        self::assertSame(100.0, CoveragePercent::of(5, 5));
        self::assertSame(8.07, CoveragePercent::of(807, 10000), '806.999… in binary floors to 8.06 without the rounding first');
        self::assertSame(0.0, CoveragePercent::of(0, 0));
        self::assertSame(28.28, CoveragePercent::floor(28.284));
        self::assertSame(28.29, CoveragePercent::floor(28.285));
    }

    public function testTheMissedLinesAreRunsAndSingles(): void
    {
        self::assertSame('12..14, 30', TextReport::missed([12 => -1, 13 => -1, 14 => -1, 20 => 1, 30 => -1]));
        self::assertSame('7', TextReport::missed([7 => -1]));
        self::assertSame('', TextReport::missed([3 => 1, 4 => -2]));
        self::assertSame('10, 12', TextReport::missed([10 => -1, 11 => -2, 12 => -1]), 'a dead line between is no run: the lines are not consecutive');

        $scattered = [];

        foreach (range(1, 40, 2) as $line) {
            $scattered[$line] = -1;
        }

        self::assertSame('1, 3, 5, 7, 9, 11, 13, 15, 17, 19, … and 10 more', TextReport::missed($scattered));
    }

    public function testTheSummaryAloneListsNoLines(): void
    {
        $data = new CoverageData(['/p/src/A.php' => [1 => 1, 2 => -1]]);

        self::assertStringContainsString('(1/2)  2', (new TextReport())->render($data, '/p', 'scripted'));
        self::assertStringNotContainsString('(1/2)', (new TextReport(onlySummary: true))->render($data, '/p', 'scripted'));
    }

    public function testATargetJudgesItsOwnFilesAlone(): void
    {
        $data = new CoverageData(
            [
                '/p/modules/Auth/A.php'      => [1 => 1, 2 => 1],
                '/p/modules/AuthExtra/B.php' => [1 => -1],
                '/p/modules/Billing/C.php'   => [1 => -1, 2 => -1],
            ],
            branches: [
                '/p/modules/Auth/A.php'    => ['b0' => ['line' => 1, 'hit' => 1]],
                '/p/modules/Billing/C.php' => ['b0' => ['line' => 1, 'hit' => 0]],
            ],
        );
        $report = new PostRunReport();

        self::assertSame(['covered' => 2, 'executable' => 5], $data->totals(), 'no target: the pooled total');

        $auth = $data->narrowedTo(['/p/modules/Auth/']);

        self::assertSame(['/p/modules/Auth/A.php'], array_keys($auth->lines), 'modules/Auth takes nothing of modules/AuthExtra');
        self::assertSame(['/p/modules/Auth/A.php'], array_keys($auth->branches), 'branches narrow with the lines');
        self::assertNull($report->coverageFloor($auth, 100.0));
        self::assertIsString($report->coverageFloor($data->narrowedTo(['/p/modules/Billing/']), 1.0));

        $nothing = $data->narrowedTo(['/p/elsewhere/']);

        self::assertSame('Coverage floor cannot be judged: the coverage scope contains no executable lines.', $report->coverageFloor($nothing, 50.0));
        self::assertNull($report->coverageFloor($nothing, 0.0));
        self::assertSame(['/p/modules/Billing/C.php'], array_keys($data->narrowedTo(['/p/modules/Billing/C.php'])->lines), 'a file target is matched whole');
    }
}
