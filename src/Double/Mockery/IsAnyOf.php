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

/**
 * Set membership behind Mockery::anyOf()/notAnyOf(). The strictness
 * is ASYMMETRIC upstream (§4 battery 8, probed both directions):
 * anyOf compares strictly ('1' is not any of (1, 2)); notAnyOf
 * compares loosely (it considers '1' a member of (1, 2)) — so the
 * negative form wraps the loose variant in LogicalNot.
 */
final class IsAnyOf extends Constraint
{
    /**
     * @param list<mixed> $set
     */
    public function __construct(
        private readonly array $set,
        private readonly bool $strict,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        // Membership of $other in the set IS the set containing it.
        return (new TraversableContains($other, $this->strict))->matches($this->set);
    }

    public function toString(): string
    {
        return 'is any of ' . Exporter::export($this->set);
    }
}
