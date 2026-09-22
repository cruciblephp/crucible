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
use ReflectionObject;

use function is_object;
use function sprintf;

final class ObjectHasProperty extends Constraint
{
    /**
     * @param non-empty-string $property
     */
    public function __construct(
        private readonly string $property,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_object($other) && (new ReflectionObject($other))->hasProperty($this->property);
    }

    public function toString(): string
    {
        return sprintf('has the property "%s"', $this->property);
    }
}
