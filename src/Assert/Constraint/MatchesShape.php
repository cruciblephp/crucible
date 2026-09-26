<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Types\TypeExpression;
use Override;

/**
 * A value fits a PHPStan type string (D-131) — the run-time half of
 * `toMatchShape()` / `assertMatchesShape()`, whose static half narrows
 * the value to the same type for the analyser. The failure names the
 * first place the value does not fit.
 */
final class MatchesShape extends Constraint
{
    private readonly TypeExpression $type;

    public function __construct(private readonly string $source)
    {
        $this->type = TypeExpression::parse($source);
    }

    #[Override]
    public function matches(mixed $other): bool
    {
        return $this->type->mismatch($other) === null;
    }

    public function toString(): string
    {
        return 'matches the type ' . $this->source;
    }

    #[Override]
    protected function additionalFailureDescription(mixed $other): string
    {
        $reason = $this->type->mismatch($other);

        return $reason === null ? '' : "\n" . $reason;
    }
}
