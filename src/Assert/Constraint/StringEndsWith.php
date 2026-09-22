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

use function is_string;
use function sprintf;
use function str_ends_with;

final class StringEndsWith extends Constraint
{
    /**
     * @param non-empty-string $suffix
     */
    public function __construct(
        private readonly string $suffix,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && str_ends_with($other, $this->suffix);
    }

    public function toString(): string
    {
        return sprintf('ends with "%s"', $this->suffix);
    }
}
