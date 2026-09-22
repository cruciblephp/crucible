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

use function is_iterable;

/**
 * The assertContainsOnly*() family: every element is of one native
 * type.
 */
final class TraversableContainsOnlyType extends Constraint
{
    public function __construct(
        private readonly ValueType $type,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_iterable($other)) {
            return false;
        }

        foreach ($other as $element) {
            if (!$this->type->check($element)) {
                return false;
            }
        }

        return true;
    }

    public function toString(): string
    {
        return 'contains only values of type ' . $this->type->value;
    }
}
