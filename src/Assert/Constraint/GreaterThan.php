<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\Exporter;
use Override;

final class GreaterThan extends Constraint
{
    public function __construct(
        private readonly mixed $bound,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return $other > $this->bound;
    }

    public function toString(): string
    {
        return 'is greater than ' . Exporter::describe($this->bound);
    }
}
