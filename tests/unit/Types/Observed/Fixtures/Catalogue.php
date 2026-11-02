<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Types\Observed\Fixtures;

use ArrayIterator as Items;
use Generator;
use InvalidArgumentException;

/**
 * Docblocks to read, some of them wrong on purpose. Only ever loaded
 * through ObservedTypesTest, rewritten with the probes.
 *
 * @phpstan-type Line array{item: string, count: int}
 */
final class Catalogue implements Pricing
{
    /** @var array<string, int> */
    private array $prices = ['tea' => 3];

    /** @return int */
    public function priceOf(string $item): ?int
    {
        return $this->prices[$item] ?? null;
    }

    /** @param non-empty-string $item */
    public function stock(string $item, int $count): int
    {
        if ($count < 0) {
            throw new InvalidArgumentException('negative');
        }

        return $count;
    }

    public function discount(int $percent): float|int
    {
        return $percent / 100;
    }

    /** @return Items<int, string> */
    public function items(): Items
    {
        return new Items(['tea']);
    }

    /** @return Line */
    public function line(): array
    {
        return ['item' => 'tea', 'count' => 1];
    }

    /** @return Generator<int, string> */
    public function lazily(): Generator
    {
        yield 'tea';
    }
}
