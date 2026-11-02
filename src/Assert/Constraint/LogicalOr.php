<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Override;

use function array_map;
use function array_values;
use function implode;

/**
 * Passes when any of its constraints does. ✓ PHPUnit 13.4.3 builds
 * assertGreaterThanOrEqual() as equal-or-greater and words it so:
 * `1 is equal to 5 or is greater than 5`.
 */
final class LogicalOr extends Constraint
{
    /** @var list<Constraint> */
    private readonly array $constraints;

    public function __construct(Constraint ...$constraints)
    {
        $this->constraints = array_values($constraints);
    }

    #[Override]
    public function matches(mixed $other): bool
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint->evaluate($other, '', true) === true) {
                return true;
            }
        }

        return false;
    }

    public function toString(): string
    {
        return implode(' or ', array_map(static fn(Constraint $constraint): string => $constraint->toString(), $this->constraints));
    }
}
