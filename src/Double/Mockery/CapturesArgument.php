<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use Closure;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use Override;

/**
 * Mockery::capture($var) — matches anything and assigns the argument
 * to the caller's variable BY REFERENCE, during the match attempt:
 * the variable is written even when a later matcher in the same
 * with() refuses the call, and the last matching call wins (both
 * oracle-pinned, §4 battery 8). The reference lives inside the
 * assigning closure, so the matcher itself stays a value object.
 */
final class CapturesArgument extends Constraint
{
    /** @var Closure(mixed): void */
    private readonly Closure $assign;

    public function __construct(mixed &$variable)
    {
        $this->assign = static function (mixed $value) use (&$variable): void {
            $variable = $value;
        };
    }

    #[Override]
    public function matches(mixed $other): bool
    {
        ($this->assign)($other);

        return true;
    }

    public function toString(): string
    {
        return 'is captured';
    }
}
