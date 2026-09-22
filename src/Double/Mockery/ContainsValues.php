<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContains;
use LucianoPereira\Crucible\Assert\Exporter;
use Override;

use function array_all;
use function is_array;

/**
 * Every value present in the argument array, any position — the shape
 * behind Mockery::contains() (LOOSE, §4 battery 8) and
 * withSomeOfArgs() (STRICT, same battery — over the whole argument
 * list). One TraversableContains per value; a non-array argument is a
 * clean no-match where the incumbent crashes (§12 posture).
 */
final class ContainsValues extends Constraint
{
    /**
     * @param list<mixed> $values
     */
    public function __construct(
        private readonly array $values,
        private readonly bool $strict,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_array($other)) {
            return false;
        }
        return array_all($this->values, fn($value) => (new TraversableContains($value, $this->strict))->matches($other));
    }

    public function toString(): string
    {
        return 'contains the values ' . Exporter::export($this->values);
    }
}
