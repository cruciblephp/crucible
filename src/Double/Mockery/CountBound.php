<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use Closure;
use LucianoPereira\Crucible\Double\InvocationCount;

/**
 * The second step of `atLeast()->times(3)` / `atMost()->once()`: a
 * pending bound waiting for its quantity.
 */
final readonly class CountBound
{
    /**
     * @param Closure(int): InvocationCount $bound
     */
    public function __construct(
        private MockeryExpectation $expectation,
        private Closure $bound,
    ) {}

    public function once(): MockeryExpectation
    {
        return $this->times(1);
    }

    public function twice(): MockeryExpectation
    {
        return $this->times(2);
    }

    public function times(int $count): MockeryExpectation
    {
        return $this->expectation->countedAs(($this->bound)($count));
    }
}
