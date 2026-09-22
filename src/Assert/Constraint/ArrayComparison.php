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

use function array_values;
use function count;
use function is_array;
use function ksort;

/**
 * The assertArrays*() family, which is four independent questions rather
 * than eight assertions — each name picks an answer to each:
 *
 * - strict: are values compared with === or ==
 * - ignoreKeys: "Have*Values" drops the keys and compares the values;
 *   "Are*" keeps them, so a differing key is a difference
 * - ignoreOrder: with keys, it is the *key order* that stops mattering
 *   (the pairing still does); without keys, the values become a multiset
 *
 * Observed, not assumed: `assertArraysAreEqual([1,2],[2,1])` fails, even
 * though both hold the same values, because the pairs 0=>1 and 0=>2
 * disagree — "ignoring order" never means "ignoring which key holds
 * what". The multiset readings keep duplicate counts, so [1,1,2] and
 * [1,2,2] stay unequal.
 */
final class ArrayComparison extends Constraint
{
    /**
     * @param array<array-key, mixed> $expected
     */
    public function __construct(
        private readonly array $expected,
        private readonly bool $strict,
        private readonly bool $ignoreKeys,
        private readonly bool $ignoreOrder,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (!is_array($other)) {
            return false;
        }

        if ($this->ignoreKeys) {
            return $this->ignoreOrder
                ? $this->sameMultiset(array_values($this->expected), array_values($other), $this->strict)
                : $this->sameSequence(array_values($this->expected), array_values($other), $this->strict);
        }

        if (!$this->strict) {
            // PHP's own == on arrays is already this question: same
            // pairs, key order irrelevant, values loose.
            return $this->expected == $other;
        }

        if (!$this->ignoreOrder) {
            return $this->expected === $other;
        }

        $expected = $this->expected;
        $actual   = $other;
        ksort($expected);
        ksort($actual);

        return $expected === $actual;
    }

    public function toString(): string
    {
        $what = $this->strict ? 'identical to' : 'equal to';

        $ignoring = match (true) {
            $this->ignoreKeys && $this->ignoreOrder => ' while ignoring keys and order',
            $this->ignoreKeys                       => ' while ignoring keys',
            $this->ignoreOrder                      => ' while ignoring order',
            default                                 => '',
        };

        return 'is ' . $what . ' ' . Exporter::describe($this->expected) . $ignoring;
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        $what = $this->strict ? 'identical' : 'equal';

        $ignoring = match (true) {
            $this->ignoreKeys && $this->ignoreOrder => ' while ignoring keys and order',
            $this->ignoreKeys                       => ' while ignoring keys',
            $this->ignoreOrder                      => ' while ignoring order',
            default                                 => '',
        };

        return 'Failed asserting that two arrays are ' . $what . $ignoring . '.';
    }

    #[Override]
    protected function comparison(mixed $other): ?ComparisonFailure
    {
        if (!is_array($other)) {
            return null;
        }

        $expected = Exporter::export($this->expected);
        $actual   = Exporter::export($other);

        return new ComparisonFailure($expected, $actual, Differ::diff($expected, $actual));
    }

    /**
     * @param list<mixed> $expected
     * @param list<mixed> $actual
     */
    private function sameSequence(array $expected, array $actual, bool $strict): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        foreach ($expected as $index => $value) {
            if ($strict ? $value !== $actual[$index] : $value != $actual[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Multiset equality by consuming matches, rather than by sorting:
     * a mixed-type list has no total order to sort by, and duplicate
     * counts have to survive the comparison.
     *
     * @param list<mixed> $expected
     * @param list<mixed> $actual
     */
    private function sameMultiset(array $expected, array $actual, bool $strict): bool
    {
        if (count($expected) !== count($actual)) {
            return false;
        }

        foreach ($expected as $value) {
            $found = false;

            foreach ($actual as $index => $candidate) {
                if ($strict ? $value === $candidate : $value == $candidate) {
                    unset($actual[$index]);
                    $found = true;

                    break;
                }
            }

            if (!$found) {
                return false;
            }
        }

        return true;
    }
}
