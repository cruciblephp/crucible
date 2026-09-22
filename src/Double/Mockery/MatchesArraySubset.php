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
use LucianoPereira\Crucible\Assert\Exporter;
use Override;

use function array_key_exists;
use function is_array;

/**
 * Mockery::subset() — the part must appear in the whole, RECURSIVELY
 * (nested part arrays are themselves subset-compared, extra keys
 * allowed at every depth), strict throughout unless the documented
 * loose flag flips the whole comparison (§4 battery 8). Keys are
 * positional for lists; a non-array argument is a clean no-match.
 */
final class MatchesArraySubset extends Constraint
{
    /**
     * @param array<array-key, mixed> $subset
     */
    public function __construct(
        private readonly array $subset,
        private readonly bool $strict = true,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_array($other) && $this->contained($this->subset, $other);
    }

    public function toString(): string
    {
        return 'contains the subset ' . Exporter::export($this->subset);
    }

    /**
     * @param array<array-key, mixed> $part
     * @param array<array-key, mixed> $whole
     */
    private function contained(array $part, array $whole): bool
    {
        foreach ($part as $key => $value) {
            if (!array_key_exists($key, $whole)) {
                return false;
            }

            $actual = $whole[$key];

            if (is_array($value) && is_array($actual)) {
                if (!$this->contained($value, $actual)) {
                    return false;
                }

                continue;
            }

            if (!($this->strict ? $value === $actual : (new IsEqual($value))->matches($actual))) {
                return false;
            }
        }

        return true;
    }
}
