<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Mutation;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Coverage\MutationIndex;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Mutation\CoveringTests;

use function array_column;

/**
 * The map is the one xdebug and pcov both report for the fixture after
 * handle() and casts() ran: lines 25 and 32 executed, 39 executable and
 * unrun, 42 the file's own return.
 */
#[CoversClass(CoveringTests::class)]
#[CoversClass(MutationIndex::class)]
final class CoveringTestsTest extends TestCase
{
    private const string FILE = 'tests/unit/Mutation/Fixtures/UnreportedLines.php';

    private const string HANDLE = 'tests/ShapeTest.php::testHandle';

    private const string CASTS = 'tests/ShapeTest.php::testCasts';

    /**
     * @param array<string, list<int>> $executable
     *
     * @return list<string>
     */
    private function covering(int $line, array $executable = [self::FILE => [25, 32, 39, 42]]): array
    {
        $map = new TestLineMap(
            [self::HANDLE => [self::FILE => [25, 42]], self::CASTS => [self::FILE => [32]]],
            $executable,
        );

        $tests = new CoveringTests(MutationIndex::build($map, [self::HANDLE => 0.5, self::CASTS => 0.1]), $map);

        return array_column($tests->of(__DIR__ . '/Fixtures/UnreportedLines.php', self::FILE, $line), 'id');
    }

    public function testAnExecutedLineKeepsItsOwnTests(): void
    {
        self::assertSame([self::HANDLE], $this->covering(25));
    }

    public function testAMethodAttributeTakesTheTestsOfTheMethodItDecorates(): void
    {
        self::assertSame([self::HANDLE], $this->covering(22));
    }

    public function testAnInnerLineOfAReturnedArrayTakesTheTestsOfItsMethod(): void
    {
        self::assertSame([self::CASTS], $this->covering(33));
    }

    public function testAClassAttributeAndAPropertyDefaultTakeEveryMethodsTestsFastestFirst(): void
    {
        self::assertSame([self::CASTS, self::HANDLE], $this->covering(17));
        self::assertSame([self::CASTS, self::HANDLE], $this->covering(20));
    }

    public function testAnExecutableLineNobodyRanStaysNotCovered(): void
    {
        self::assertSame([], $this->covering(39));
    }

    public function testAFileWithNoReportedLinesStaysNotCovered(): void
    {
        self::assertSame([], $this->covering(22, executable: []));
    }

    public function testAMatchArmTakesTheTestsOfEveryLineOfItsMatch(): void
    {
        $file = 'tests/unit/Mutation/Fixtures/MatchArms.php';
        $both = 'tests/ProcessorTest.php::testColumn#unconstrained';
        $one  = 'tests/ProcessorTest.php::testColumn#scale only';
        $int  = 'tests/ProcessorTest.php::testColumn#integer';
        $map  = new TestLineMap(
            [$both => [$file => [21, 22, 25]], $one => [$file => [21, 23, 25]], $int => [$file => [21, 23, 24, 25]], self::HANDLE => [$file => [30]]],
            [$file => [21, 22, 23, 24, 25, 30]],
        );
        $tests = new CoveringTests(MutationIndex::build($map, [$both => 0.1, $one => 0.2, $int => 0.3, self::HANDLE => 0.4]), $map);
        $of    = static fn(int $line): array => array_column($tests->of(__DIR__ . '/Fixtures/MatchArms.php', $file, $line), 'id');

        self::assertSame([$both, $one, $int], $of(22), 'an arm line reported only for the call that matched it');
        self::assertSame([$both, $one, $int], $of(23));
        self::assertSame([self::HANDLE], $of(30), 'a line after the match keeps its own tests');
    }
}
