<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Examples\Equivalent;

use LucianoPereira\Crucible\Framework\TestCase;

final class Inventory
{
    /** @var list<string> */
    public array $log = [];

    /**
     * @param list<string> $items
     *
     * @crucible-equivalent the loop returns before the bound matters
     */
    public function first(array $items): ?string
    {
        for ($i = 0; $i < count($items); $i++) {
            return $items[$i];
        }

        return null;
    }

    public function restock(int $count): int
    {
        $this->log[] = 'restocked ' . ($count + 1); // crucible-equivalent-line the log wording is not behaviour

        return $count;
    }
}

/**
 * `crucible mutate` turns `<` into `<=` in first(): no test can tell,
 * because the loop returns on its first pass. The marker says so, with
 * the reason, and the mutant leaves the score while staying in the report.
 */
final class InventoryTest extends TestCase
{
    public function testFirstIsTheFirstItemOrNull(): void
    {
        $inventory = new Inventory();

        self::assertSame('a', $inventory->first(['a', 'b']));
        self::assertNull($inventory->first([]));
    }

    public function testRestockReturnsTheCount(): void
    {
        self::assertSame(3, (new Inventory())->restock(3));
    }
}
