<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Override;

use function is_float;
use function is_infinite;

final class IsInfinite extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_float($other) && is_infinite($other);
    }

    public function toString(): string
    {
        return 'is infinite';
    }
}
