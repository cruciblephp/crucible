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
use Override;

use function array_all;
use function implode;
use function is_object;
use function method_exists;

/**
 * Mockery::ducktype() — the argument is an object declaring every
 * named method (method_exists; magic __call methods are NOT seen —
 * §4 battery 8). Non-objects are a no-match.
 */
final class HasDuckType extends Constraint
{
    /**
     * @param list<non-empty-string> $methods
     */
    public function __construct(
        private readonly array $methods,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_object($other)) {
            return false;
        }
        return array_all($this->methods, fn($method) => method_exists($other, $method));
    }

    public function toString(): string
    {
        return 'declares the methods ' . implode(', ', $this->methods);
    }
}
