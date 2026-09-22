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
use LucianoPereira\Crucible\Assert\Constraint\IsEqual;
use LucianoPereira\Crucible\Assert\Constraint\IsIdentical;

use function is_object;

/**
 * The Mockery grammar's default argument equality, oracle-pinned
 * (spec §4, the inverse of the PHPUnit spec on both counts): scalars
 * and arrays compare loosely (`with(1)` matches `'1'`), objects
 * compare by IDENTITY (equal-by-value instances do not match).
 * Constraint instances pass through — that is M3's matcher seam.
 *
 * @internal
 */
final readonly class MockeryEquality
{
    /**
     * @param array<mixed> $arguments variadics may carry named keys
     *
     * @return list<Constraint>
     */
    public static function matchers(array $arguments): array
    {
        $matchers = [];

        foreach ($arguments as $argument) {
            $matchers[] = match (true) {
                $argument instanceof Constraint => $argument,
                is_object($argument)            => new IsIdentical($argument),
                default                         => new IsEqual($argument),
            };
        }

        return $matchers;
    }
}
