<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
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
        $inVitest = $this->root() . '/src/Vitest/VitestReport.tsx';
        $inImpact = $this->root() . '/src/Impact/VitestImpact.tsx';

        [$suites] = VitestImpact::select(
            [new VitestSuite('src/Vitest'), new VitestSuite('src/Impact')],
            new ChangedFiles([$inVitest, $inImpact]),
            new WorkingDirectory($this->root()),
        );

        // Each suite is offered both in-root changes: a package boundary
        // is not a module boundary, and Vitest's graph decides whether
        // the other package's file reaches any test here.
        self::assertSame(
            [['src/Vitest', [$inVitest, $inImpact]], ['src/Impact', [$inImpact, $inVitest]]],
            array_map(static fn(VitestSuite $suite): array => [$suite->directory, $suite->related], $suites),
        );
    }

    public function testAChangeInsideOneSuiteRootIsOfferedToTheOther(): void
    {
        $button = $this->root() . '/packages/ui/src/Button.tsx';

        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('packages/ui'), new VitestSuite('packages/app')],
            new ChangedFiles([$button]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame(
            [['packages/ui', [$button]], ['packages/app', [$button]]],
            array_map(static fn(VitestSuite $suite): array => [$suite->directory, $suite->related], $suites),
            'The app suite imports a module that lives inside the ui suite root.',
        );
        self::assertStringContainsString('outside its own root', $notes[1]);
    }

    public function testARootLockfileRunsEverySuiteInFull(): void
    {
        $lockfile = $this->root() . '/pnpm-lock.yaml';

        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('src/Vitest'), new VitestSuite('src/Impact')],
            new ChangedFiles([$lockfile]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame([[], []], array_map(static fn(VitestSuite $suite): array => $suite->related, $suites));
        self::assertStringContainsString('configures it, running the whole suite', $notes[0]);
        self::assertStringContainsString('configures it, running the whole suite', $notes[1]);
    }

    public function testASuiteLocalConfigurationRunsOnlyThatSuiteInFull(): void
    {
        $config = $this->root() . '/src/Vitest/vitest.config.ts';

        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('src/Vitest'), new VitestSuite('src/Impact')],
            new ChangedFiles([$config]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame(
            [['src/Vitest', []]],
            array_map(static fn(VitestSuite $suite): array => [$suite->directory, $suite->related], $suites),
            'The sibling suite must not answer for another package configuration.',
        );
        self::assertStringContainsString('no JS changes, skipped', $notes[1]);
    }

    public function testATypeScriptConfigurationRunsTheSuiteBelowIt(): void
    {
        $config = $this->root() . '/src/Vitest/tsconfig.build.json';

        [$suites] = VitestImpact::select(
            [new VitestSuite('src/Vitest')],
            new ChangedFiles([$config]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame([[]], array_map(static fn(VitestSuite $suite): array => $suite->related, $suites));
    }

    public function testASharedModuleOutsideEveryRootIsOfferedToEachSuite(): void
    {
        $shared = $this->root() . '/resources/js/shared/format.ts';

        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('src/Vitest'), new VitestSuite('src/Impact')],
            new ChangedFiles([$shared]),
            new WorkingDirectory($this->root()),
        );

        self::assertSame(
            [['src/Vitest', [$shared]], ['src/Impact', [$shared]]],
            array_map(static fn(VitestSuite $suite): array => [$suite->directory, $suite->related], $suites),
            'Vitest\'s own graph decides which suite the shared module reaches.',
        );
        self::assertStringContainsString('shared change(s) outside its root', $notes[0]);
    }

    public function testAFileThatIsNeitherAModuleNorAConfigurationLeavesTheSuitesOut(): void
    {
        [$suites, $notes] = VitestImpact::select(
            [new VitestSuite('src/Vitest')],
            new ChangedFiles([$this->root() . '/README.md']),
            new WorkingDirectory($this->root()),
        );

        self::assertSame([], $suites);
        self::assertStringContainsString('no JS changes, skipped', $notes[0]);
    }
}
