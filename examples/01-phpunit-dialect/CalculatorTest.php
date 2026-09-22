<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Examples\PhpUnitDialect;

use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Attributes\Depends;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_sum;

/**
 * The PHPUnit dialect — the drop-in one.
 *
 * A suite written for PHPUnit runs here unchanged: same base class, same
 * assertions, same attributes. The only difference in this file is the
 * namespace the attributes come from, and even that is optional — with
 * the compatibility aliases enabled, `PHPUnit\Framework\TestCase` and
 * `PHPUnit\Framework\Attributes\*` resolve too.
 */
final class CalculatorTest extends TestCase
{
    public function testAddsTwoNumbers(): void
    {
        $this->assertSame(4, 2 + 2);
    }

    /**
     * A data provider expands at discovery time, so each row is its own
     * test with its own name — a failing row names itself.
     *
     * @return iterable<string, array{list<int>, int}>
     */
    public static function sums(): iterable
    {
        yield 'empty' => [[], 0];
        yield 'one item' => [[7], 7];
        yield 'several' => [[1, 2, 3], 6];
    }

    #[DataProvider('sums')]
    public function testSumsAList(array $numbers, int $expected): void
    {
        $this->assertSame($expected, array_sum($numbers));
    }

    /**
     * Groups are how you slice a suite from the command line
     * (`--group slow`) and what declared impact rules point at.
     */
    #[Group('arithmetic')]
    public function testMultiplies(): int
    {
        $product = 6 * 7;

        $this->assertSame(42, $product);

        return $product;
    }

    /**
     * A dependent test receives what its dependency returned, and is
     * skipped rather than failed when the dependency did not pass —
     * an unmet dependency reports as *untested*, not as a defect.
     */
    #[Depends('testMultiplies')]
    public function testUsesTheProductOfItsDependency(int $product): void
    {
        $this->assertSame(42, $product);
    }
}
