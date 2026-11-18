<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Php;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Configuration\TestSuite;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\TestDiscoverer;

use function array_map;
use function basename;
use function dirname;
use function sort;

/**
 * Content-based dialect routing for files that don't carry the
 * explicit `.pest.php`/`.crucible.php` suffix — the fallback that
 * lets Crucible discover real-world Pest suites (spatie/laravel-data
 * confirmed as the real project this was built against: every one of
 * its 72 Pest files is a plain `*Test.php`, the same convention
 * PHPUnit-dialect files use).
 */
#[CoversClass(TestDiscoverer::class)]
final class TestDiscovererTest extends TestCase
{
    public function testRoutesAPlainTestPhpFileWithOnlyPestCallsToThePestDialect(): void
    {
        $groups = $this->discover('no-class');

        self::assertCount(1, $groups);
        self::assertCount(2, $groups[0]->tests);
    }

    public function testRoutesTheNestedClassRegressionFixtureToThePestDialectWithoutCrashing(): void
    {
        $groups = $this->discover('nested-class');

        self::assertCount(1, $groups);
        self::assertCount(1, $groups[0]->tests);
    }

    public function testSkipsAFileWithNeitherAClassNorPestCallsSilently(): void
    {
        self::assertSame([], $this->discover('no-signal'));
    }

    public function testRoutesAFileWithATopLevelHelperClassAndTopLevelPestCallsToPestDialect(): void
    {
        // Real-world proof this isn't ambiguous: spatie/laravel-data's
        // InjectPropertyValuesTest.php declares a top-level
        // #[Attribute] class right next to real it() calls. Pest
        // calls win; the class is simply not a TestCase candidate.
        $groups = $this->discover('class-and-pest-calls');

        self::assertCount(1, $groups);
        self::assertCount(1, $groups[0]->tests);
    }

    public function testCollectsTheClassWhenTheOnlyPestCallDeclaresNoTest(): void
    {
        // uses() binds a base class; it does not declare a test. A file
        // whose only pest vocabulary is uses() therefore builds an EMPTY
        // pest group, and the tests it was binding for may be the class
        // declared in that same file.
        //
        // The incumbent collects that class -- it runs PHPUnit
        // underneath and does not treat the two as exclusive. Crucible
        // returned the empty group and stopped, which is a false green
        // at the level of discovery: measured against spatie/schema-org
        // on Pest 5.1.1, the incumbent ran 1,926 tests and Crucible ran
        // 64 and exited 0.
        $groups = $this->discover('uses-only-with-class');

        self::assertCount(1, $groups);
        self::assertCount(2, $groups[0]->tests);
    }

    /**
     * The spec's bare arguments (D-152): ✓ PHPUnit 13.4.3 runs the files and
     * directories given instead of the configured suites, a path outside
     * them included, whatever --testsuite names.
     */
    public function testPathsRunInsteadOfTheConfiguredSuites(): void
    {
        $root          = dirname(__DIR__, 3);
        $configuration = new Configuration([new TestSuite('main', ['tests/_fixtures/dialect-detection/no-class'])], new Source(), new Php());
        $discoverer    = new TestDiscoverer();
        $directory     = new WorkingDirectory($root);

        $outside = $discoverer->discover($configuration, $directory, ['main'], paths: ['tests/_fixtures/dialect-detection/nested-class/NestedClassPestTest.php']);
        self::assertCount(1, $outside);
        self::assertCount(1, $outside[0]->tests);

        $both = $discoverer->discover($configuration, $directory, paths: ['tests/_fixtures/dialect-detection/nested-class', 'tests/_fixtures/dialect-detection/no-class']);
        self::assertCount(2, $both);
    }

    /**
     * The worker's rebuild: unit files routed directly must produce the
     * same ids the full pass produced for those files — dataset rows
     * included. PHPUnit-dialect files survive a second discovery in
     * one process (classes already declared are recognized, not
     * re-required); the Pest dialect re-requires its file by design,
     * so its parity is pinned by the subprocess evidence in
     * WorkerUnitDiscoveryTest.
     */
    public function testUnitFilesRediscoverToTheSameIdsAsTheFullPass(): void
    {
        $root      = dirname(__DIR__, 3);
        $directory = new WorkingDirectory($root);

        $files = [
            'tests/unit/Metadata/FixtureParent.php',
            'tests/unit/Metadata/FixtureChild.php',
        ];

        // The suite names exactly these files, so the full pass and the
        // unit rebuild see the same set.
        $configuration = new Configuration([new TestSuite('main', [], $files)], new Source(), new Php());

        $full = (new TestDiscoverer())->discover($configuration, $directory);

        $rediscovered = (new TestDiscoverer())->discoverUnits(
            $configuration,
            $directory,
            array_map(static fn(string $file): string => $directory->absolute($file), $files),
        );

        self::assertSame($this->idsOf($full), $this->idsOf($rediscovered));
    }

    public function testAnInlineCarrierBuildsThroughTheInlineFrontend(): void
    {
        // The inline scan's exclusive territory: a source file with
        // markers, reached by name the way a worker's unit reaches it.
        $root      = dirname(__DIR__, 3);
        $directory = new WorkingDirectory($root);
        $file      = 'tests/unit/Dialect/Fixtures/Inline/Temperature.php';

        $inside = new Configuration([new TestSuite('main', ['tests/_fixtures/dialect-detection/no-class'])], new Source(includeDirectories: ['tests/unit/Dialect/Fixtures/Inline']), new Php());

        $groups = (new TestDiscoverer())->discoverUnits($inside, $directory, [$directory->absolute($file)]);

        self::assertNotSame([], $groups, 'A unit file the source scan owns must build through the inline frontend.');
        self::assertSame('Temperature.php', basename($groups[0]->tests[0]->id->file));
    }

    public function testAnInlineCarrierOutsideTheSourceIncludesIsNotBuiltInline(): void
    {
        // No source includes, no inline scan in the parent's pass: the
        // same file must not gain a group the parent never made. A
        // positional-paths run (D-152) is the same case.
        $root      = dirname(__DIR__, 3);
        $directory = new WorkingDirectory($root);

        $configuration = new Configuration([new TestSuite('main', ['tests/_fixtures/dialect-detection/no-class'])], new Source(), new Php());

        $groups = (new TestDiscoverer())->discoverUnits($configuration, $directory, [$directory->absolute('tests/unit/Dialect/Fixtures/Inline/Temperature.php')]);

        self::assertSame([], $groups);
    }

    public function testAFileThatVanishedProducesNoGroup(): void
    {
        $root      = dirname(__DIR__, 3);
        $directory = new WorkingDirectory($root);

        $configuration = new Configuration([new TestSuite('main', ['tests/_fixtures/dialect-detection/no-signal'])], new Source(), new Php());

        $groups = (new TestDiscoverer())->discoverUnits($configuration, $directory, [$directory->absolute('tests/_fixtures/dialect-detection/no-signal/RenamedAwayTest.php')]);

        self::assertSame([], $groups);
    }

    /**
     * @param list<\LucianoPereira\Crucible\Test\TestGroup> $groups
     *
     * @return list<string>
     */
    private function idsOf(array $groups): array
    {
        $ids = [];

        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                $ids[] = $test->id->toString();
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return list<\LucianoPereira\Crucible\Test\TestGroup>
     */
    private function discover(string $fixtureDirectory): array
    {
        $root   = dirname(__DIR__, 3);
        $suites = [new TestSuite('main', ['tests/_fixtures/dialect-detection/' . $fixtureDirectory])];

        $configuration = new Configuration($suites, new Source(), new Php());

        return (new TestDiscoverer())->discover($configuration, new WorkingDirectory($root));
    }
}
