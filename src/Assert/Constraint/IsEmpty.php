<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Countable;
use Override;

use function count;

final class IsEmpty extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        if ($other instanceof Countable) {
            return count($other) === 0;
        }

        return empty($other);
    }

    public function toString(): string
    {
        return 'is empty';
    }
}
