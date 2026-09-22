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

use function array_is_list;
use function is_array;

final class IsList extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_array($other) && array_is_list($other);
    }

    public function toString(): string
    {
        return 'is a list';
    }
}
