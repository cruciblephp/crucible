<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Polyfill\Php85;

use LucianoPereira\Crucible\Framework\TestCase;

use function _crucible_array_first;
use function _crucible_array_last;

/**
 * Exercises the internals directly (never the guarded public names)
 * so coverage does not depend on which PHP version runs the suite:
 * on 8.5+ the public array_first/array_last are the native functions,
 * and these _crucible_* internals would otherwise go untested here.
 */
final class ArrayFunctionsTest extends TestCase
{
    public function testFirstReturnsNullOnAnEmptyArray(): void
    {
        $this->assertNull(_crucible_array_first([]));
    }

    public function testFirstReturnsTheFirstValueOfAListArray(): void
    {
        $this->assertSame(1, _crucible_array_first([1, 2, 3]));
    }

    public function testFirstReturnsTheFirstValueOfAnAssociativeArray(): void
    {
        $this->assertSame('x', _crucible_array_first(['a' => 'x', 'b' => 'y']));
    }

    public function testFirstReturnsNullWhenTheFirstValueIsItselfNull(): void
    {
        $this->assertNull(_crucible_array_first([null, 1, 2]));
    }

    public function testLastReturnsNullOnAnEmptyArray(): void
    {
        $this->assertNull(_crucible_array_last([]));
    }

    public function testLastReturnsTheLastValueOfAListArray(): void
    {
        $this->assertSame(3, _crucible_array_last([1, 2, 3]));
    }

    public function testLastReturnsTheLastValueOfAnAssociativeArray(): void
    {
        $this->assertSame('y', _crucible_array_last(['a' => 'x', 'b' => 'y']));
    }

    public function testLastReturnsNullWhenTheLastValueIsItselfNull(): void
    {
        $this->assertNull(_crucible_array_last([1, 2, null]));
    }
}
