<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Fixtures\InlineInvalid;

use LucianoPereira\Crucible\Attributes\Check;

/** @phpcpd-keep Negative fixture: proves the uninstantiable-class load error. */
final readonly class NeedsConstructorArgs
{
    public function __construct(
        private int $seed,
    ) {}

    #[Check(returns: 1)]
    public function value(): int
    {
        return $this->seed;
    }
}
