<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\Listing;

use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\TestWith;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * Listed and documented by ListingTest; the expected texts there are
 * PHPUnit 13.4.3's for the same class.
 */
final class ListedTest extends TestCase
{
    /** @return array<array-key, array{int}> */
    public static function rows(): array
    {
        return ['first' => [1], [2], 5 => [3]];
    }

    #[Group('slow')]
    #[Group('db')]
    public function testA(): void
    {
        self::assertTrue(true);
    }

    #[Group('db')]
    public function testFails(): void
    {
        self::assertTrue(false);
    }

    public function testIsRisky(): void {}

    #[DataProvider('rows')]
    public function testRows(int $n): void
    {
        self::assertSame(1, $n);
    }

    #[TestWith([1])]
    #[TestWith([2], 'named')]
    #[TestWith([3])]
    public function testWith(int $n): void
    {
        self::assertGreaterThan(0, $n);
    }
}
