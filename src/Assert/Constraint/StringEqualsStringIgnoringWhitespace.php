<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\ComparisonFailure;
use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Assert\Exporter;
use Override;

use function is_string;
use function preg_replace;
use function trim;

/**
 * Whitespace-insensitive string equality: every run of whitespace
 * collapses to one space and the ends are trimmed.
 *
 * Collapsed, not removed — observed, because the two readings disagree
 * on the case that matters: "a b" and "ab" are NOT equal here, so the
 * assertion still distinguishes two words from one. Tabs, newlines and
 * CRLF all reduce to the same single space.
 */
final class StringEqualsStringIgnoringWhitespace extends Constraint
{
    public function __construct(
        private readonly string $expected,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && self::normalize($other) === self::normalize($this->expected);
    }

    public function toString(): string
    {
        return 'is equal to ' . Exporter::describe($this->expected) . ' ignoring whitespace';
    }

    protected function comparison(mixed $other): ?ComparisonFailure
    {
        if (!is_string($other)) {
            return null;
        }

        $expected = Exporter::export(self::normalize($this->expected));
        $actual   = Exporter::export(self::normalize($other));

        return new ComparisonFailure($expected, $actual, Differ::diff($expected, $actual));
    }

    public static function normalize(string $string): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $string));
    }
}
