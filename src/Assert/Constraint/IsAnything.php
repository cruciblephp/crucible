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

/**
 * Matches any value — the placeholder constraint for a mock's
 * `->with()` positional argument you don't want to constrain.
 */
final class IsAnything extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return true;
    }

    public function toString(): string
    {
        return 'is anything';
    }
}
