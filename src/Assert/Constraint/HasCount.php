<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Generator;
use Override;
use Traversable;

use function count;
use function is_countable;
use function iterator_count;
use function sprintf;

final class HasCount extends Constraint
{
    public function __construct(
        private readonly int $expected,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return $this->countOf($other) === $this->expected;
    }

    public function toString(): string
    {
        return sprintf('has a count of %d', $this->expected);
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        $actual = $this->countOf($other);

        return $actual === null
            ? parent::failureDescription($other)
            : sprintf('Failed asserting that actual size %d matches expected size %d.', $actual, $this->expected);
    }

    private function countOf(mixed $other): ?int
    {
        if (is_countable($other)) {
            return count($other);
        }

        if ($other instanceof Generator) {
            // Deliberate: counting would exhaust the generator.
            return null;
        }

        if ($other instanceof Traversable) {
            return iterator_count($other);
        }

        return null;
    }
}
