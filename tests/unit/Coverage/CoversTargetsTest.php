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
use LucianoPereira\Crucible\Attributes\CoversNothing;
use LucianoPereira\Crucible\Attributes\UsesClass;
use LucianoPereira\Crucible\Coverage\CoverageWindow;
use LucianoPereira\Crucible\Coverage\CoversTargets;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use ReflectionClass;

use function spl_autoload_register;
use function spl_autoload_unregister;
use function str_replace;

/** The covered fixture — a real class so reflection has real ranges. */
final class ProbeCalculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

/** The stray fixture — executed but never listed. */
final class ProbeHelper
{
    public static function help(): int
    {
        return 7;
    }
}

/**
 * D-063: the covers-claim semantics, each oracle-pinned by the probe
 * battery (2026-07-17 against phpunit-main 13.3-dev + xdebug).
 */
#[CoversClass(CoversTargets::class)]
final class CoversTargetsTest extends TestCase
{
    private function windowTouchingBoth(): CoverageWindow
    {
        $calculator = new ReflectionClass(ProbeCalculator::class);
        $helper     = new ReflectionClass(ProbeHelper::class);

        return new CoverageWindow(
            [__FILE__ => [
                (int) $calculator->getStartLine() + 2 => 1,  // inside ProbeCalculator
                (int) $helper->getStartLine() + 2     => 1,  // inside ProbeHelper
                (int) $helper->getStartLine() + 3     => -1, // missed line, stays a denominator
            ]],
            [__FILE__ => [
                'add@0'  => ['line' => (int) $calculator->getStartLine() + 2, 'hit' => 1],
                'help@0' => ['line' => (int) $helper->getStartLine() + 2, 'hit' => 1],
            ]],
        );
    }

    public function testNoClaimMeansIdentityContributionAndNoStrays(): void
    {
        $targets = CoversTargets::from(MetadataCollection::from());
        $window  = $this->windowTouchingBoth();

        self::assertFalse($targets->declared);
        self::assertSame($window->lines, $targets->contribution($window)->lines);
        self::assertSame([], $targets->strays($window));
    }

    public function testCoversClassFiltersContributionByDemotingStrayHits(): void
    {
        $targets = CoversTargets::from(MetadataCollection::from(new CoversClass(ProbeCalculator::class)));
        $window  = $this->windowTouchingBoth();

        $contribution = $targets->contribution($window);
        $helper       = new ReflectionClass(ProbeHelper::class);
        $calculator   = new ReflectionClass(ProbeCalculator::class);

        // The stray hit is DEMOTED to executable-missed, never
        // dropped — the oracle keeps the denominators (probe-pinned).
        self::assertSame(-1, $contribution->lines[__FILE__][(int) $helper->getStartLine() + 2]);
        self::assertSame(1, $contribution->lines[__FILE__][(int) $calculator->getStartLine() + 2]);
        self::assertSame(-1, $contribution->lines[__FILE__][(int) $helper->getStartLine() + 3]);
        self::assertSame(0, $contribution->branches[__FILE__]['help@0']['hit']);
        self::assertSame(1, $contribution->branches[__FILE__]['add@0']['hit']);
    }

    public function testStraysNameTheDeclaringUnit(): void
    {
        $targets = CoversTargets::from(MetadataCollection::from(new CoversClass(ProbeCalculator::class)));

        self::assertSame([ProbeHelper::class], $targets->strays($this->windowTouchingBoth()));
    }

    public function testUsesClassAllowsExecutionButNotContribution(): void
    {
        $targets = CoversTargets::from(MetadataCollection::from(
            new CoversClass(ProbeCalculator::class),
            new UsesClass(ProbeHelper::class),
        ));

        $window = $this->windowTouchingBoth();
        $helper = new ReflectionClass(ProbeHelper::class);

        self::assertSame([], $targets->strays($window)); // used — no longer a stray
        self::assertSame(-1, $targets->contribution($window)->lines[__FILE__][(int) $helper->getStartLine() + 2]); // but never counted
    }

    public function testCoversNothingDeclaresContributesNothingAndIsExemptFromStrict(): void
    {
        $targets = CoversTargets::from(MetadataCollection::from(new CoversNothing()));
        $window  = $this->windowTouchingBoth();

        self::assertTrue($targets->declared);
        self::assertSame([], $targets->strays($window)); // exempt (probe E)

        foreach ($targets->contribution($window)->lines[__FILE__] as $value) {
            self::assertLessThan(1, $value); // every hit demoted
        }
    }

    public function testUnknownTargetsResolveToNoRangeNotAnError(): void
    {
        /** @var class-string $ghost a name nothing declares — kept opaque to the analyzer */
        $ghost   = str_replace('!', '', 'Totally\Unknown\Covers!Ghost');
        $targets = CoversTargets::from(MetadataCollection::from(new CoversClass($ghost)));
        $window  = $this->windowTouchingBoth();

        self::assertTrue($targets->declared);
        // Everything executed is a stray against an empty target set.
        self::assertSame([ProbeCalculator::class, ProbeHelper::class], $targets->strays($window));
    }

    public function testATargetThatCannotLoadResolvesToNoRangeToo(): void
    {
        // The sibling of the case above, and the one that bites: the
        // name IS declared, but declaring it throws — an optional
        // package's base class is missing. Crucible's own laravel
        // bridge is such a class wherever illuminate is not installed,
        // and resolving one #[CoversClass] naming it ended the whole
        // run before a single report was written. Measured against the
        // oracle (phpunit 13.3.1 + xdebug, same shape): exit 0, target
        // unresolved. So it lands where an undeclared name lands.
        $loader = static function (string $class): void {
            if ($class === 'CrucibleProbe\\Unloadable\\Target') {
                require __DIR__ . '/../../_fixtures/coverage-targets/unloadable-target.php';
            }
        };

        spl_autoload_register($loader);

        try {
            /** @var class-string $unloadable kept opaque to the analyzer, as above */
            $unloadable = str_replace('!', '', 'CrucibleProbe\\Unloadable!Target');
            $targets    = CoversTargets::from(MetadataCollection::from(new CoversClass($unloadable)));
            $window     = $this->windowTouchingBoth();

            self::assertTrue($targets->declared);
            self::assertSame([ProbeCalculator::class, ProbeHelper::class], $targets->strays($window));
        } finally {
            spl_autoload_unregister($loader);
        }
    }

    public function testWindowWithoutHitsKeepsDenominators(): void
    {
        $demoted = $this->windowTouchingBoth()->withoutHits();

        foreach ($demoted->lines[__FILE__] as $value) {
            self::assertLessThan(1, $value);
        }

        self::assertCount(3, $demoted->lines[__FILE__]); // nothing dropped
        self::assertSame(0, $demoted->branches[__FILE__]['add@0']['hit']);
    }
}
