<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Property;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Property\ChoiceSource;
use LucianoPereira\Crucible\Property\Gen;
use LucianoPereira\Crucible\Property\Property;
use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_reverse;
use function array_sum;
use function count;
use function in_array;
use function is_int;
use function str_contains;
use function strlen;

#[CoversClass(Property::class)]
#[CoversClass(Gen::class)]
#[CoversClass(ChoiceSource::class)]
final class PropertyTest extends TestCase
{
    public function testATruePropertyHolds(): void
    {
        Property::forAll(Gen::int(), Gen::int())
            ->check(static fn(int $a, int $b) => Assert::assertSame($a + $b, $b + $a));
    }

    public function testListReversalRoundTrips(): void
    {
        Property::forAll(Gen::listOf(Gen::int(-50, 50)))
            ->check(static fn(array $items) => Assert::assertSame($items, array_reverse(array_reverse($items))));
    }

    public function testAFalsePropertyIsFalsifiedAndShrunkToTheBoundary(): void
    {
        try {
            Property::forAll(Gen::int(0, 10_000))
                ->seed(20260715)
                ->check(static fn(int $n) => Assert::assertLessThan(100, $n));
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Property falsified', $failure->getMessage());
            self::assertStringContainsString('Counterexample: 100', $failure->getMessage());
            self::assertStringContainsString('seed 20260715', $failure->getMessage());
            self::assertStringContainsString('Replay with ->seed(20260715)', $failure->getMessage());

            return;
        }

        self::fail('The false property was not falsified.');
    }

    public function testShrinkingFindsTheMinimalList(): void
    {
        try {
            // "No list sums past 100" is false; the minimal witness
            // is the single-element [100]. Reaching it needs the
            // redistribute-and-delete pass (D-040) — plain shortlex
            // edits stall at two-element splits like [10, 90].
            Property::forAll(Gen::listOf(Gen::int(0, 100)))
                ->seed(42)
                ->check(static function (array $items): void {
                    Assert::assertLessThan(100, array_sum($items));
                });
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Counterexample: [100]', $failure->getMessage());

            return;
        }

        self::fail('The false property was not falsified.');
    }

    public function testShrinkingSurvivesMap(): void
    {
        $even = Gen::int(0, 1_000)->map(static fn(int $n): int => $n * 2);

        try {
            Property::forAll($even)
                ->seed(7)
                ->check(static fn(int $n) => Assert::assertLessThan(50, $n));
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Counterexample: 50', $failure->getMessage());

            return;
        }

        self::fail('The false property was not falsified.');
    }

    public function testReturningFalseFalsifies(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('returned false');

        Property::forAll(Gen::bool())->seed(1)->check(static fn(bool $b): bool => false);
    }

    public function testTheSameSeedReproducesTheSameCounterexample(): void
    {
        self::assertSame($this->falsification(), $this->falsification());
    }

    /**
     * @return non-empty-string
     */
    private function falsification(): string
    {
        try {
            Property::forAll(Gen::int(0, 1_000_000))
                ->seed(99)
                ->check(static fn(int $n) => Assert::assertLessThan(1_000, $n));
        } catch (AssertionFailedError $failure) {
            $message = $failure->getMessage();

            return $message !== '' ? $message : self::fail('An empty falsification message.');
        }

        self::fail('The false property was not falsified.');
    }

    public function testSuchThatFiltersAndStaysShrinkable(): void
    {
        $odd = Gen::int(0, 1_000)->suchThat(static fn(int $n): bool => $n % 2 === 1);

        Property::forAll($odd)
            ->cases(50)
            ->check(static fn(int $n) => Assert::assertSame(1, $n % 2));
    }

    public function testAnImpossibleFilterIsAConfigurationProblem(): void
    {
        $this->expectException(ConfigurationException::class);

        Property::forAll(Gen::int(0, 10)->suchThat(static fn(int $n): bool => $n > 10))
            ->check(static fn(int $n) => Assert::assertTrue(true));
    }

    public function testGeneratorsProduceTheirDeclaredShapes(): void
    {
        Property::forAll(
            Gen::int(-5, 5),
            Gen::bool(),
            Gen::elementOf(['espresso', 'ristretto', 'lungo']),
            Gen::string(10),
            Gen::oneOf(Gen::constant('fixed'), Gen::int(0, 1)),
        )->cases(50)->check(static function (int $i, bool $b, string $coffee, string $s, mixed $mixed): void {
            Assert::assertGreaterThanOrEqual(-5, $i);
            Assert::assertLessThanOrEqual(5, $i);
            Assert::assertContains($coffee, ['espresso', 'ristretto', 'lungo']);
            Assert::assertLessThanOrEqual(10, strlen($s));
            Assert::assertTrue($mixed === 'fixed' || (is_int($mixed) && in_array($mixed, [0, 1], true)));
            Assert::assertIsBool($b);
        });
    }

    public function testIntegersShrinkTowardZeroNotTheLowerBound(): void
    {
        try {
            // Falsified by anything non-zero; the minimal witness by
            // magnitude within [-1000, 1000] must be ±1, never -1000.
            Property::forAll(Gen::int(-1_000, 1_000))
                ->seed(5)
                ->check(static fn(int $n) => Assert::assertSame(0, $n));
        } catch (AssertionFailedError $failure) {
            $message = $failure->getMessage();

            self::assertTrue(
                str_contains($message, 'Counterexample: 1') || str_contains($message, 'Counterexample: -1'),
                'Expected a ±1 counterexample, got: ' . $message,
            );

            return;
        }

        self::fail('The false property was not falsified.');
    }

    public function testChoiceSourceReplaysAndClampsScripts(): void
    {
        $source = new ChoiceSource(null, [7, 99, -3]);

        self::assertSame(7, $source->draw(10));
        self::assertSame(5, $source->draw(5));   // clamped into bound
        self::assertSame(0, $source->draw(10));  // negatives are zero
        self::assertSame(0, $source->draw(10));  // exhausted → simplest
        self::assertSame([7, 5, 0, 0], $source->choices());
    }

    public function testFloatsStayInRangeAndShapeUp(): void
    {
        Property::forAll(Gen::float(-2.5, 7.25))
            ->cases(200)
            ->check(static function (float $f): void {
                Assert::assertGreaterThanOrEqual(-2.5, $f);
                Assert::assertLessThanOrEqual(7.25, $f);
            });
    }

    public function testFloatsShrinkToTheSimplestSpecialValue(): void
    {
        try {
            // Falsified by anything >= 0.5; the simplest in-range
            // special that fails is 1.0.
            Property::forAll(Gen::float(0.0, 10.0))
                ->seed(3)
                ->check(static fn(float $f) => Assert::assertLessThan(0.5, $f));
        } catch (AssertionFailedError $failure) {
            self::assertStringContainsString('Counterexample: 1.0', $failure->getMessage());

            return;
        }

        self::fail('The false property was not falsified.');
    }

    /**
     * The D-069 regression: the dyadic fraction's 1/65536 quantum is
     * wider than a narrow range, so the old encoding collapsed
     * [0, 1e-6] to two distinct values (probed: 20k draws) and could
     * never falsify inside it. Narrow ranges now use evenly spaced
     * points — resolution scales with the range.
     */
    public function testNarrowFloatRangesResolve(): void
    {
        $generator = Gen::float(0.0, 1.0e-6);
        $random    = new Randomizer(new Mt19937(4242));
        $seen      = [];

        for ($i = 0; $i < 2_000; $i++) {
            $seen[(string) $generator->generate(new ChoiceSource($random))] = true;
        }

        // The probe's floor: was 2 distinct; even spacing gives
        // thousands. Anything above 500 proves the quantum resolves.
        Assert::assertGreaterThan(500, count($seen));

        // The other collapse: a range straddling zero with no whole
        // number below its midpoint could never produce an interior
        // negative (the fraction only added). Now it can.
        $generator = Gen::float(-1.0e-7, 1.0e-7);
        $random    = new Randomizer(new Mt19937(4242));
        $negative  = false;

        for ($i = 0; $i < 2_000 && !$negative; $i++) {
            $value    = $generator->generate(new ChoiceSource($random));
            $negative = $value < 0.0 && $value > -1.0e-7;
        }

        Assert::assertTrue($negative, 'No interior negative was ever generated.');
    }

    public function testNarrowFloatFalsifiesInsideTheRangeAndShrinksToItsEdge(): void
    {
        try {
            // The failure region is the middle 60% of [0, 1e-6] — the
            // old encoding could only produce the endpoints and never
            // falsified this. Shrinking walks the counterexample down
            // toward the region's lower edge (2e-7); the pin asserts
            // it lands in the 2.x band just above it, not the exact
            // step-budget-dependent value.
            Property::forAll(Gen::float(0.0, 1.0e-6))
                ->seed(11)
                ->check(static function (float $f): void {
                    Assert::assertFalse($f > 2.0e-7 && $f < 8.0e-7, 'inside the failure region');
                });
        } catch (AssertionFailedError $failure) {
            self::assertMatchesRegularExpression('/Counterexample: 2\.\d+E-7/', $failure->getMessage());

            return;
        }

        self::fail('The false property was not falsified.');
    }

    public function testFloatRejectsANonFiniteOrInvertedRange(): void
    {
        $this->expectException(ConfigurationException::class);

        Gen::float(5.0, 1.0);
    }

    public function testForAllNeedsAGenerator(): void
    {
        $this->expectException(ConfigurationException::class);

        Property::forAll();
    }

    public function testIntRejectsAnInvertedRange(): void
    {
        $this->expectException(ConfigurationException::class);

        Gen::int(5, 1);
    }
}
