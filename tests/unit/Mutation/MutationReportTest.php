<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutationOutcome;
use LucianoPereira\Crucible\Mutation\MutationReport;
use LucianoPereira\Crucible\Mutation\MutationVerdict;

#[CoversClass(MutationReport::class)]
#[CoversClass(MutationVerdict::class)]
final class MutationReportTest extends TestCase
{
    private function mutant(): Mutant
    {
        return new Mutant('/project/src/Foo.php', 'Foo', 1, 'm', '<?php');
    }

    public function testTheScoreIsDetectedOverCoveredMutants(): void
    {
        $mutant = $this->mutant();

        $report = new MutationReport([
            MutationVerdict::killed($mutant, 't::a', 0.0),
            MutationVerdict::killed($mutant, 't::b', 0.0),
            MutationVerdict::escaped($mutant, 0.0),
            MutationVerdict::timedOut($mutant, 0.0),
            MutationVerdict::errored($mutant, 'boom', 0.0),
            MutationVerdict::notCovered($mutant),
        ]);

        self::assertSame(6, $report->total());
        self::assertSame(5, $report->covered(), 'Not-covered is excluded from the covered set.');
        self::assertSame(4, $report->detected(), 'Killed + timed out + errored are all detections.');
        self::assertSame(2, $report->count(MutationOutcome::Killed));
        // detected 4 / covered 5 = 80%.
        self::assertEqualsWithDelta(80.0, $report->score(), 1e-9);
    }

    public function testAllUncoveredScoresZeroNotADivideByZero(): void
    {
        $report = new MutationReport([MutationVerdict::notCovered($this->mutant())]);

        self::assertSame(0, $report->covered());
        self::assertSame(0.0, $report->score());
    }
}
