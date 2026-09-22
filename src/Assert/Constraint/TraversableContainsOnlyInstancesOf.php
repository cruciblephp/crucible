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

use function is_iterable;

final class TraversableContainsOnlyInstancesOf extends Constraint
{
    /**
     * @param class-string $className
     */
    public function __construct(
        private readonly string $className,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_iterable($other)) {
            return false;
        }

        foreach ($other as $element) {
            if (!$element instanceof $this->className) {
                return false;
            }
        }

        return true;
    }

    public function toString(): string
    {
        return 'contains only instances of ' . $this->className;
    }
}
