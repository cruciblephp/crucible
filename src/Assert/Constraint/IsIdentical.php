<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\ComparisonFailure;
use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Assert\Exporter;
use Override;

use function is_array;
use function is_object;
use function is_string;

/**
 * assertSame(): === semantics — value and type for scalars/arrays,
 * same instance for objects.
 */
final class IsIdentical extends Constraint
{
    public function __construct(
        private readonly mixed $expected,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return $this->expected === $other;
    }

    public function toString(): string
    {
        return 'is identical to ' . Exporter::describe($this->expected);
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        if (is_string($this->expected) && is_string($other)) {
            return 'Failed asserting that two strings are identical.';
        }

        if (is_array($this->expected) && is_array($other)) {
            return 'Failed asserting that two arrays are identical.';
        }

        if (is_object($this->expected) && is_object($other) && $this->expected::class === $other::class) {
            return 'Failed asserting that two variables reference the same object.';
        }

        return parent::failureDescription($other);
    }

    protected function comparison(mixed $other): ?ComparisonFailure
    {
        $comparable = (is_string($this->expected) && is_string($other))
            || (is_array($this->expected) && is_array($other))
            || (is_object($this->expected) && is_object($other));

        if (!$comparable) {
            return null;
        }

        $expected = Exporter::export($this->expected);
        $actual   = Exporter::export($other);

        return new ComparisonFailure($expected, $actual, Differ::diff($expected, $actual));
    }
}
