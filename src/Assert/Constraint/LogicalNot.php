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

use function str_contains;
use function str_replace;

/**
 * Negates any constraint; every assertNot*() is LogicalNot around the
 * positive form.
 */
final class LogicalNot extends Constraint
{
    public function __construct(
        private readonly Constraint $constraint,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return !$this->constraint->matches($other);
    }

    public function toString(): string
    {
        $positive = $this->constraint->toString();

        foreach (['is ' => 'is not ', 'has ' => 'does not have ', 'contains ' => 'does not contain ', 'matches ' => 'does not match ', 'starts with ' => 'does not start with ', 'ends with ' => 'does not end with ', 'exists' => 'does not exist'] as $from => $to) {
            if (str_contains($positive, $from)) {
                return str_replace($from, $to, $positive);
            }
        }

        return 'not( ' . $positive . ' )';
    }
}
