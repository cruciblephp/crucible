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

use function is_array;
use function is_iterable;

/**
 * assertContains() (identical, ===) and assertContainsEquals()
 * (loose, via IsEqual) over any iterable.
 */
final class TraversableContains extends Constraint
{
    public function __construct(
        private readonly mixed $needle,
        private readonly bool $strict = true,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_iterable($other)) {
            return false;
        }

        $equal = new IsEqual($this->needle);

        foreach ($other as $element) {
            if ($this->strict ? $element === $this->needle : $equal->matches($element)) {
                return true;
            }
        }

        return false;
    }

    public function toString(): string
    {
        return 'contains ' . PhpUnitExporter::export($this->needle);
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return 'Failed asserting that ' . (is_array($other) ? 'an array' : 'a traversable') . ' ' . $this->toString() . '.';
    }
}
