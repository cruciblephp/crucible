<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Closure;
use Override;

/**
 * An arbitrary predicate as a constraint — the extension point custom
 * expectations (Pest's expect()->extend) compile onto.
 */
final class Callback extends Constraint
{
    /**
     * @param Closure(mixed): bool    $predicate
     * @param ?Closure(mixed): string $failure   the whole failure sentence, for a matcher that words its own
     */
    public function __construct(
        private readonly Closure $predicate,
        private readonly string $description = 'is accepted by callback',
        private readonly ?Closure $failure = null,
    ) {}

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return $this->failure instanceof Closure ? ($this->failure)($other) : parent::failureDescription($other);
    }

    #[Override]
    public function matches(mixed $other): bool
    {
        return ($this->predicate)($other);
    }

    public function toString(): string
    {
        return $this->description;
    }
}
