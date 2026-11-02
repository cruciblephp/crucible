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
        return sprintf('has property "%s"', $this->property);
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return sprintf('Failed asserting that %s %s.', is_object($other) ? 'object of class "' . $other::class . '"' : PhpUnitExporter::export($other), $this->toString());
    }
}
