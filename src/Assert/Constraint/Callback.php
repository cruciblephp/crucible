<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Closure;
use Override;

/**
 * An arbitrary predicate as a constraint — the extension point custom
 * expectations (Pest's expect()->extend) compile onto.
 */
final class Callback extends Constraint
{
    /**
     * @param Closure(mixed): bool $predicate
     */
    public function __construct(
        private readonly Closure $predicate,
        private readonly string $description = 'is accepted by callback',
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return ($this->predicate)($other);
    }

    public function toString(): string
    {
        return $this->description;
    }
}
