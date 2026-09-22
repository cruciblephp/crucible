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
use LucianoPereira\Crucible\Assert\Constraint\MatchesRegularExpression;
use Override;
use Stringable;

use function is_scalar;
use function sprintf;

/**
 * Mockery::pattern() — the regex over the argument CAST to string:
 * scalars and Stringable objects match through (string) (§4 battery
 * 8: '/12/' matches int 123 and float 12.5); a non-stringable object
 * is a clean no-match where the incumbent crashes (§12 posture). The
 * cast is this wrapper; the regex itself is MatchesRegularExpression.
 */
final class MatchesPattern extends Constraint
{
    /**
     * @param non-empty-string $pattern
     */
    public function __construct(
        private readonly string $pattern,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_scalar($other) && !$other instanceof Stringable) {
            return false;
        }

        return (new MatchesRegularExpression($this->pattern))->matches((string) $other);
    }

    public function toString(): string
    {
        return sprintf('matches the pattern "%s"', $this->pattern);
    }
}
