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

use function is_readable;
use function is_string;

final class IsReadable extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && is_readable($other);
    }

    public function toString(): string
    {
        return 'is readable';
    }
}
