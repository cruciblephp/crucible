<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
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

use function dirname;

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
