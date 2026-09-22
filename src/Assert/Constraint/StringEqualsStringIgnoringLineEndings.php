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
use function str_replace;

final class StringEqualsStringIgnoringLineEndings extends Constraint
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
        return 'is equal to ' . Exporter::describe($this->expected) . ' ignoring line endings';
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
        return str_replace(["\r\n", "\r"], "\n", $string);
    }
}
