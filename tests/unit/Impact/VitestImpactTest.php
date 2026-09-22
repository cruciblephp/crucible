<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Impact\ChangedFiles;
use LucianoPereira\Crucible\Impact\VitestImpact;
use LucianoPereira\Crucible\Vitest\VitestSuite;

use function array_map;
use function dirname;

#[CoversClass(VitestImpact::class)]
final class VitestImpactTest extends TestCase
{
    /**
     * @return non-empty-string
     */
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testAChangeUnderASuiteNarrowsItToThatRelatedFile(): void
    {
        $file = $this->root() . '/src/Vitest/VitestSuite.php';

        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('src/Vitest')],
            new ChangedFiles([$file]),
            new WorkingDirectory($this->root()),
        );

        self::assertCount(1, $suites);
        self::assertSame([$file], $suites[0]->related);
        self::assertStringContainsString('running related', $notes[0]);
    }

    public function testASuiteWithNoJsChangeIsDroppedButNamed(): void
    {
        $file = $this->root() . '/src/Version.php';

        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('src/Vitest')],
            new ChangedFiles([$file]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame([], $suites);
        self::assertStringContainsString('no JS changes, skipped', $notes[0]);
    }

    public function testDeletionsWidenEverySuiteToAFullRun(): void
    {
        $suite = new VitestSuite('src/Vitest');

        [$suites, $notes] = VitestImpact::select(
            [$suite],
            new ChangedFiles([], ['src/Vitest/Gone.js']),
            new WorkingDirectory($this->root()),
        );

        self::assertSame([$suite], $suites);
        self::assertSame([], $suites[0]->related, 'A widened suite carries no related narrowing.');
        self::assertStringContainsString('runs in full', $notes[0]);
    }

    public function testNoConfiguredSuitesYieldsNothing(): void
    {
        self::assertSame(
            [[], []],
            VitestImpact::select([], new ChangedFiles([$this->root() . '/src/Version.php']), new WorkingDirectory($this->root())),
        );
    }

    public function testEachSuiteIsScopedIndependently(): void
    {
        $inVitest = $this->root() . '/src/Vitest/VitestReport.php';
        $inImpact = $this->root() . '/src/Impact/VitestImpact.php';

        [$suites] = VitestImpact::select(
            [new VitestSuite('src/Vitest'), new VitestSuite('src/Impact')],
            new ChangedFiles([$inVitest, $inImpact]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame(
            [['src/Vitest', [$inVitest]], ['src/Impact', [$inImpact]]],
            array_map(static fn(VitestSuite $suite): array => [$suite->directory, $suite->related], $suites),
        );
    }
}
