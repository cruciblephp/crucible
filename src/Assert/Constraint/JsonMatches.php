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
use function json_decode;
use function json_validate;

/**
 * assertJsonStringEqualsJsonString(): equality of the decoded
 * documents — formatting, key order, and whitespace are irrelevant;
 * values and structure are not.
 */
final class JsonMatches extends Constraint
{
    public function __construct(
        private readonly string $expectedJson,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_string($other) || !json_validate($other) || !json_validate($this->expectedJson)) {
            return false;
        }

        $expected = json_decode($this->expectedJson, true);
        $actual   = json_decode($other, true);

        return (new IsEqual($expected))->matches($actual);
    }

    public function toString(): string
    {
        return 'matches JSON string "' . $this->expectedJson . '"';
    }

    protected function comparison(mixed $other): ?ComparisonFailure
    {
        if (!is_string($other) || !json_validate($other) || !json_validate($this->expectedJson)) {
            return null;
        }

        $expected = Exporter::export(json_decode($this->expectedJson, true));
        $actual   = Exporter::export(json_decode($other, true));

        return new ComparisonFailure($expected, $actual, Differ::diff($expected, $actual));
    }
}
