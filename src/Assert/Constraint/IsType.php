<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\ValueType;
use Override;

final class IsType extends Constraint
{
    public function __construct(
        private readonly ValueType $type,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return $this->type->check($other);
    }

    public function toString(): string
    {
        return 'is of type ' . $this->type->value;
    }
}
