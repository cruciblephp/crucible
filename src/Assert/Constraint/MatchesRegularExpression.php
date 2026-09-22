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
use function preg_match;
use function sprintf;

final class MatchesRegularExpression extends Constraint
{
    /**
     * @param non-empty-string $pattern
     */
    public function __construct(
        private readonly string $pattern,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && preg_match($this->pattern, $other) === 1;
    }

    public function toString(): string
    {
        return sprintf('matches regular expression "%s"', $this->pattern);
    }
}
