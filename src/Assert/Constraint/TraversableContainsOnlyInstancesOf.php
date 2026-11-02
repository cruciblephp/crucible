<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\PhpUnitExporter;
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
        return 'contains only values of type "' . $this->className . '"';
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return 'Failed asserting that ' . PhpUnitExporter::export($other) . ' ' . $this->toString() . '.';
    }
}
