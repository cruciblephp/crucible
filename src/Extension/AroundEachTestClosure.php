<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Extension;

use Closure;
use LucianoPereira\Crucible\Test\TestId;

/**
 * The {@see AroundEachTest} role as a plain callable, registered by
 * {@see \LucianoPereira\Crucible\Configuration\Builder::aroundEachTest()}.
 * A closure cannot implement an interface, so this is the instance the
 * configuration carries — through the role dispatch, so it runs in every
 * process that loads the configuration file (D-172).
 */
final readonly class AroundEachTestClosure implements AroundEachTest
{
    /**
     * @param Closure(TestId, callable(): mixed): mixed $around
     */
    public function __construct(
        private Closure $around,
    ) {}

    public function around(TestId $id, callable $test): mixed
    {
        return ($this->around)($id, $test);
    }
}
