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
use function str_starts_with;

final class StringStartsWith extends Constraint
{
    /**
     * @param non-empty-string $prefix
     */
    public function __construct(
        private readonly string $prefix,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && str_starts_with($other, $this->prefix);
    }

    public function toString(): string
    {
        return sprintf('starts with "%s"', $this->prefix);
    }
}
