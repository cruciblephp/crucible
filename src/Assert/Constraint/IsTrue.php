<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Override;

final class IsTrue extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return $other === true;
    }

    public function toString(): string
    {
        return 'is true';
    }
}
