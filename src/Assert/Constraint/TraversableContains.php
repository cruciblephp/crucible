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
        return 'contains ' . Exporter::describe($this->needle);
    }
}
