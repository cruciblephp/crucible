<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use DateTimeInterface;
use LucianoPereira\Crucible\Assert\ComparisonFailure;
use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Assert\Exporter;
use Override;
use Stringable;

use function abs;
use function array_all;
use function array_key_exists;
use function count;
use function get_mangled_object_vars;
use function is_array;
use function is_float;
use function is_int;
use function is_nan;
use function is_numeric;
use function is_object;
use function is_string;
use function mb_strtolower;
use function spl_object_id;

/**
 * assertEquals(): the loose-equality spec — numeric juggling between
 * numbers and numeric strings (but never between two strings),
 * order-insensitive key comparison for arrays, timestamp comparison
 * for DateTimeInterface, property-wise recursion for same-class
 * objects. `$delta` applies to every numeric comparison in the
 * recursion; `$canonicalize` compares arrays as multisets.
 *
 * Fine-grained parity with the spec's comparator behavior is pinned
 * by the conformance suite (ROADMAP Phase 9).
 */
final class IsEqual extends Constraint
{
    public function __construct(
        private readonly mixed $expected,
        private readonly float $delta = 0.0,
        private readonly bool $canonicalize = false,
        private readonly bool $ignoreCase = false,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        return $this->equals($this->expected, $other, []);
    }

    public function toString(): string
    {
        return 'is equal to ' . Exporter::describe($this->expected);
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        if (is_string($this->expected) && is_string($other)) {
            return 'Failed asserting that two strings are equal.';
        }

        if (is_array($this->expected) && is_array($other)) {
            return 'Failed asserting that two arrays are equal.';
        }

        if (is_object($this->expected) && is_object($other)) {
            return 'Failed asserting that two objects are equal.';
        }

        return parent::failureDescription($other);
    }

    protected function comparison(mixed $other): ?ComparisonFailure
    {
        $expected = Exporter::export($this->expected);
        $actual   = Exporter::export($other);

        if ($expected === $actual) {
            // Equal exports that still failed (identity/juggling nuance):
            // a diff would show nothing useful.
            return null;
        }

        return new ComparisonFailure($expected, $actual, Differ::diff($expected, $actual));
    }

    /**
     * @param list<array{int, int}> $visited object-pair recursion guard
     */
    private function equals(mixed $a, mixed $b, array $visited): bool
    {
        if ($a === $b) {
            return true;
        }

        $pair = $this->numericPair($a, $b);

        if ($pair !== null) {
            [$floatA, $floatB] = $pair;

            if (is_nan($floatA) || is_nan($floatB)) {
                return false;
            }

            return abs($floatA - $floatB) <= $this->delta;
        }

        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return abs((float) $a->format('U.u') - (float) $b->format('U.u')) <= $this->delta;
        }

        if (is_array($a) && is_array($b)) {
            return $this->canonicalize
                ? $this->equalAsMultisets($a, $b, $visited)
                : $this->equalAsMaps($a, $b, $visited);
        }

        if (is_object($a) && is_object($b)) {
            return $this->objectsEqual($a, $b, $visited);
        }

        // A string compared against a Stringable object is compared as
        // strings — real PHPUnit's own ScalarComparator does exactly
        // this ("allow comparison between strings and objects featuring
        // __toString()"), confirmed against a real spatie/laravel-data
        // case: a validation rule attribute equals its string form.
        if (is_string($a) && $this->isStringable($b)) {
            return $this->equals($a, (string) $b, $visited);
        }

        if (is_string($b) && $this->isStringable($a)) {
            return $this->equals((string) $a, $b, $visited);
        }

        if (is_object($a) || is_object($b) || is_array($a) || is_array($b)) {
            return false;
        }

        // Remaining scalars/null: loose comparison, except two strings
        // (already handled by === above; unequal strings stay unequal
        // unless case is being ignored).
        if (is_string($a) && is_string($b)) {
            return $this->ignoreCase && mb_strtolower($a) === mb_strtolower($b);
        }

        return $a == $b;
    }

    /**
     * @phpstan-assert-if-true Stringable $value
     */
    private function isStringable(mixed $value): bool
    {
        return $value instanceof Stringable;
    }

    /**
     * @return ?array{float, float}
     */
    private function numericPair(mixed $a, mixed $b): ?array
    {
        if (!is_int($a) && !is_float($a) && (!is_int($b) && !is_float($b))) {
            return null;
        }

        if (!is_numeric($a) || !is_numeric($b)) {
            return null;
        }

        return [(float) $a, (float) $b];
    }

    /**
     * @param array<array-key, mixed>  $a
     * @param array<array-key, mixed>  $b
     * @param list<array{int, int}>    $visited
     */
    private function equalAsMaps(array $a, array $b, array $visited): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }
        return array_all($a, fn($value, $key) => array_key_exists((string) $key, $b) && $this->equals($value, $b[$key], $visited));
    }

    /**
     * @param array<array-key, mixed>  $a
     * @param array<array-key, mixed>  $b
     * @param list<array{int, int}>    $visited
     */
    private function equalAsMultisets(array $a, array $b, array $visited): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        $remaining = $b;

        foreach ($a as $value) {
            foreach ($remaining as $key => $candidate) {
                if ($this->equals($value, $candidate, $visited)) {
                    unset($remaining[$key]);

                    continue 2;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * @param list<array{int, int}> $visited
     */
    private function objectsEqual(object $a, object $b, array $visited): bool
    {
        if ($a::class !== $b::class) {
            return false;
        }

        $pair = [spl_object_id($a), spl_object_id($b)];

        foreach ($visited as $seen) {
            if ($seen === $pair) {
                return true;
            }
        }

        $visited[] = $pair;

        return $this->equalAsMaps(get_mangled_object_vars($a), get_mangled_object_vars($b), $visited);
    }
}
