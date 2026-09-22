<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Coverage\MutationIndex;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_column;

/**
 * The mutation-facing query index (D-077, half A): the inverse of the
 * per-test line map, joined with the result cache's timings and ordered
 * fastest-first — the "which tests cover this line, cheapest to run
 * first" answer a mutation tool needs.
 */
#[CoversClass(MutationIndex::class)]
final class MutationIndexTest extends TestCase
{
    public function testInvertsTheMapAndOrdersCoveringTestsFastestFirst(): void
    {
        $map = new TestLineMap([
            'tests/A.php::slow' => ['src/Calc.php' => [10, 11]],
            'tests/B.php::fast' => ['src/Calc.php' => [10]],
        ]);

        $covering = MutationIndex::build($map, [
            'tests/A.php::slow' => 0.5,
            'tests/B.php::fast' => 0.01,
        ])->coveringTests('src/Calc.php', 10);

        $this->assertSame(['tests/B.php::fast', 'tests/A.php::slow'], array_column($covering, 'id'));
        $this->assertSame(0.01, $covering[0]['duration']);
    }

    public function testUntimedTestsSortLastAndTiesBreakDeterministically(): void
    {
        $map = new TestLineMap([
            'tests/Z.php::untimed' => ['src/X.php' => [1]],
            'tests/A.php::untimed' => ['src/X.php' => [1]],
            'tests/M.php::timed'   => ['src/X.php' => [1]],
        ]);

        $covering = MutationIndex::build($map, ['tests/M.php::timed' => 0.2])->coveringTests('src/X.php', 1);

        // The timed test first, then the untimed pair ordered by id — a
        // replayable order, never dependent on map insertion.
        $this->assertSame(
            ['tests/M.php::timed', 'tests/A.php::untimed', 'tests/Z.php::untimed'],
            array_column($covering, 'id'),
        );
        $this->assertNull($covering[1]['duration']);
    }

    public function testAnUncoveredLineOrFileHasNoTests(): void
    {
        $index = MutationIndex::build(new TestLineMap(['t' => ['src/X.php' => [1]]]), []);

        $this->assertSame([], $index->coveringTests('src/X.php', 99));
        $this->assertSame([], $index->coveringTests('src/Other.php', 1));
    }
}
