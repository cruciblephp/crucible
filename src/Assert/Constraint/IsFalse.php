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

final class IsFalse extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return $other === false;
    }

    public function toString(): string
    {
        return 'is false';
    }
}
