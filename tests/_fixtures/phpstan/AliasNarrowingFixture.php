<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan;

use DateTimeImmutable;

use function intdiv;
use function strlen;

/*
 * Analyzed by the black-box extension test (D-049), never executed.
 * Three claims in one file, one phpstan run:
 *
 * - extending the PHPUnit-shaped name proves the analysis-time
 *   aliases resolved (otherwise: class.notFound);
 * - the narrowed calls prove assert narrowing through the aliased
 *   parent (otherwise: argument.type on each);
 * - the deliberate sentinel error proves the analysis is not
 *   vacuously green;
 * - the named-argument call narrows too — a D-049 FINDING: PHPStan
 *   normalizes named arguments before type-specifying extensions see
 *   the node, so the extension's positional guard is a fallback for
 *   unreadable shapes, not the named-argument path.
 */

/** @phpcpd-keep Analysed by ExtensionBlackBoxTest through phpstan, never referenced in code. */
final class AliasNarrowingFixture extends \PHPUnit\Framework\TestCase
{
    public function probe(mixed $m, ?string $maybe, mixed $obj): int
    {
        $this->assertIsInt($m);
        $narrowedByInstanceCall = $this->half($m);

        self::assertNotNull($maybe);
        $narrowedByStaticCall = $this->stringLength($maybe);

        self::assertInstanceOf(DateTimeImmutable::class, $obj);
        $narrowedInstance = $obj->getTimestamp();

        $sentinel = $this->half('the one intended error'); // sentinel

        return $narrowedByInstanceCall + $narrowedByStaticCall + $narrowedInstance + $sentinel;
    }

    public function namedArgumentsNarrowToo(mixed $n): int
    {
        self::assertIsInt(actual: $n);

        return $this->half($n); // clean: normalized upstream, narrowed
    }

    /**
     * The D-051 pins, exact-typed via dumpType: per-position closure
     * parameters recovered from the forAll chain, through cases()
     * and through map().
     */
    public function propertyClosuresAreTyped(): void
    {
        \LucianoPereira\Crucible\Property\Property::forAll(
            \LucianoPereira\Crucible\Property\Gen::int(),
            \LucianoPereira\Crucible\Property\Gen::listOf(\LucianoPereira\Crucible\Property\Gen::string()),
        )->cases(5)->check(function ($number, $strings): void {
            \PHPStan\dumpType($number);
            \PHPStan\dumpType($strings);
        });
    }

    /**
     * The D-067 pins: the property() sugar types its closure from the
     * generator arguments of the same call, and a DECLARED parameter
     * that cannot accept its generator's production is reported (the
     * acceptance rule) — two sentinels, one per surface.
     */
    public function propertySugarAndAcceptance(): void
    {
        \property('the sugar types its closure', \LucianoPereira\Crucible\Property\Gen::bool(), function ($flag): void {
            \PHPStan\dumpType($flag);
        });

        \LucianoPereira\Crucible\Property\Property::forAll(
            \LucianoPereira\Crucible\Property\Gen::int(),
        )->check(function (string $wrong): void {}); // acceptance sentinel

        \property('sugar acceptance', \LucianoPereira\Crucible\Property\Gen::string(), function (int $alsoWrong): void {}); // acceptance sentinel

        // Not a sentinel: PHP passes an int to a float parameter even
        // under strict types, so this closure takes what its generator
        // produces (D-137).
        \LucianoPereira\Crucible\Property\Property::forAll(\LucianoPereira\Crucible\Property\Gen::int())
            ->check(function (float $widened): void {});
    }

    private function half(int $value): int
    {
        return intdiv($value, 2);
    }

    private function stringLength(string $value): int
    {
        return strlen($value);
    }
}
