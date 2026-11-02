<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Countable;
use Override;

use function count;
use function gettype;
use function is_array;
use function is_object;
use function is_string;

final class IsEmpty extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        if ($other instanceof Countable) {
            return count($other) === 0;
        }

        return empty($other);
    }

    public function toString(): string
    {
        return 'is empty';
    }

    /** ✓ PHPUnit 13.4.3 names the kind: `an array`, `a string`, `an object`, else `a ` and gettype(): `a integer`. */
    #[Override]
    protected function failureDescription(mixed $other): string
    {
        $subject = match (true) {
            is_array($other)  => 'an array',
            is_string($other) => 'a string',
            is_object($other) => 'an object',
            default           => 'a ' . gettype($other),
        };

        return 'Failed asserting that ' . $subject . ' ' . $this->toString() . '.';
    }
}
