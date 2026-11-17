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
 * Passes when every one of its constraints does. PHPUnit's name for it
 * is built by Symfony's BrowserKit and DomCrawler assertion traits
 * (`LogicalAnd::fromConstraints(...)`), so the class also exists under
 * that name once compatibility aliases are loaded.
 */
final class LogicalAnd extends Constraint
{
    /** @var list<Constraint> */
    private readonly array $constraints;

    public function __construct(Constraint ...$constraints)
    {
        $this->constraints = array_values($constraints);
    }

    /**
     * The form frameworks construct: Symfony's traits hand it the
     * constraints they built, never an array.
     */
    public static function fromConstraints(Constraint ...$constraints): self
    {
        return new self(...$constraints);
    }

    #[Override]
    public function matches(mixed $other): bool
    {
        foreach ($this->constraints as $constraint) {
            if ($constraint->evaluate($other, '', true) !== true) {
                return false;
            }
        }

        // No constraints is PHPUnit's vacuous truth (and() over an
        // empty set), not a failure to assert anything.
        return true;
    }

    public function toString(): string
    {
        return implode(' and ', array_map(static fn(Constraint $constraint): string => $constraint->toString(), $this->constraints));
    }
}
