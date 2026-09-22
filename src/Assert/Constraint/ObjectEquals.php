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

use function is_bool;
use function is_object;
use function method_exists;
use function sprintf;

/**
 * assertObjectEquals(): delegates equality to the value object's own
 * comparison method — $actual->equals($expected) by default — and
 * requires that method to return a bool.
 */
final class ObjectEquals extends Constraint
{
    /**
     * @param non-empty-string $method
     */
    public function __construct(
        private readonly object $expected,
        private readonly string $method = 'equals',
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_object($other) || !method_exists($other, $this->method)) {
            return false;
        }

        $result = $other->{$this->method}($this->expected);

        return is_bool($result) && $result;
    }

    public function toString(): string
    {
        return sprintf(
            'is equal to %s according to %s::%s()',
            $this->expected::class,
            $this->expected::class,
            $this->method,
        );
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        if (is_object($other) && !method_exists($other, $this->method)) {
            return sprintf(
                'Failed asserting that two objects are equal: %s has no %s() method.',
                $other::class,
                $this->method,
            );
        }

        return parent::failureDescription($other);
    }
}
