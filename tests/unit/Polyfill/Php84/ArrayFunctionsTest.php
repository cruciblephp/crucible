<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Polyfill\Php84;

use LucianoPereira\Crucible\Framework\TestCase;

use function _crucible_array_all;
use function _crucible_array_any;
use function _crucible_array_find;
use function _crucible_array_find_key;

/**
 * Exercises the internals directly (never the guarded public names)
 * so coverage does not depend on which PHP version runs the suite:
 * on 8.4+ the public array_any/array_all/array_find/array_find_key
 * are the native functions, and these _crucible_* internals would
 * otherwise go untested here.
 */
final class ArrayFunctionsTest extends TestCase
{
    public function testAnyIsFalseOnAnEmptyArray(): void
    {
        $this->assertFalse(_crucible_array_any([], static fn(mixed $value): bool => true));
    }

    public function testAnyIsFalseWhenNoElementMatches(): void
    {
        $this->assertFalse(_crucible_array_any([1, 2, 3], static fn(int $value): bool => $value > 10));
    }

    public function testAnyIsTrueWhenAnElementMatches(): void
    {
        $this->assertTrue(_crucible_array_any([1, 2, 3], static fn(int $value): bool => $value === 2));
    }

    public function testAnyPassesTheKeyToTheCallback(): void
    {
        $this->assertTrue(_crucible_array_any(
            ['a' => 1, 'b' => 2],
            static fn(int $value, string $key): bool => $key === 'b',
        ));
    }

    public function testAllIsTrueOnAnEmptyArray(): void
    {
        $this->assertTrue(_crucible_array_all([], static fn(mixed $value): bool => false));
    }

    public function testAllIsTrueWhenEveryElementMatches(): void
    {
        $this->assertTrue(_crucible_array_all([2, 4, 6], static fn(int $value): bool => $value % 2 === 0));
    }

    public function testAllIsFalseWhenAnyElementFails(): void
    {
        $this->assertFalse(_crucible_array_all([2, 4, 5], static fn(int $value): bool => $value % 2 === 0));
    }

    public function testAllPassesTheKeyToTheCallback(): void
    {
        $this->assertFalse(_crucible_array_all(
            ['a' => 1, 'b' => 2],
            static fn(int $value, string $key): bool => $key === 'a',
        ));
    }

    public function testFindReturnsNullOnAnEmptyArray(): void
    {
        $this->assertNull(_crucible_array_find([], static fn(mixed $value): bool => true));
    }

    public function testFindReturnsNullWhenNoElementMatches(): void
    {
        $this->assertNull(_crucible_array_find([1, 2, 3], static fn(int $value): bool => $value > 10));
    }

    public function testFindReturnsTheFirstMatchingValue(): void
    {
        $this->assertSame(4, _crucible_array_find([1, 3, 4, 6], static fn(int $value): bool => $value % 2 === 0));
    }

    public function testFindPassesTheKeyToTheCallback(): void
    {
        $this->assertSame(
            2,
            _crucible_array_find(['a' => 1, 'b' => 2], static fn(int $value, string $key): bool => $key === 'b'),
        );
    }

    public function testFindKeyReturnsNullOnAnEmptyArray(): void
    {
        $this->assertNull(_crucible_array_find_key([], static fn(mixed $value): bool => true));
    }

    public function testFindKeyReturnsNullWhenNoElementMatches(): void
    {
        $this->assertNull(_crucible_array_find_key([1, 2, 3], static fn(int $value): bool => $value > 10));
    }

    public function testFindKeyReturnsTheFirstMatchingKey(): void
    {
        $this->assertSame(
            'b',
            _crucible_array_find_key(['a' => 1, 'b' => 2], static fn(int $value, string $key): bool => $key === 'b'),
        );
    }

    public function testFindKeyShortCircuitsOnTheFirstMatch(): void
    {
        $seen = [];

        _crucible_array_find_key([1, 2, 3, 4], static function (int $value) use (&$seen): bool {
            $seen[] = $value;

            return $value === 2;
        });

        $this->assertSame([1, 2], $seen);
    }
}
