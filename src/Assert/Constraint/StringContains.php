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
use function mb_stripos;
use function sprintf;
use function str_contains;

final class StringContains extends Constraint
{
    public function __construct(
        private readonly string $needle,
        private readonly bool $ignoreCase = false,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_string($other)) {
            return false;
        }

        if ($this->needle === '') {
            return true;
        }

        return $this->ignoreCase
            ? mb_stripos($other, $this->needle) !== false
            : str_contains($other, $this->needle);
    }

    public function toString(): string
    {
        return sprintf(
            "contains \"%s\"%s",
            $this->needle,
            $this->ignoreCase ? ' (ignoring case)' : '',
        );
    }
}
