<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use Closure;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function array_values;
use function ceil;
use function count;
use function floor;
use function in_array;
use function intdiv;
use function is_finite;
use function max;
use function mb_str_split;
use function min;
use function sprintf;

use const PHP_INT_MAX;

/**
 * Value generators for property-based testing (growth G5). A Gen is
 * one closure from the choice stream to a value; combinators compose
 * closures, never touch randomness directly, and stay shrinkable for
 * free — the shrinker edits the underlying choices, and simpler
 * choices flow through map() and suchThat() into simpler values.
 *
 * Generic over what it produces (D-051): Gen<int>, Gen<list<string>>
 * — map() and suchThat() carry the type through, and the PHPStan
 * extension hands check() closures their real parameter types.
 *
 * @template-covariant T
 */
final readonly class Gen
{
    /**
     * @param Closure(ChoiceSource): T $build
     */
    private function __construct(
        private Closure $build,
    ) {}

    /**
     * @return T
     */
    public function generate(ChoiceSource $source): mixed
    {
        return ($this->build)($source);
    }

    /**
     * Integers in [$min, $max], shrinking toward the value of
     * smallest magnitude the range allows (0 when it contains 0 —
     * the Hypothesis ordering, not "toward $min").
     *
     * @return self<int>
     */
    public static function int(int $min = -1_000_000, int $max = 1_000_000): self
    {
        if ($min > $max) {
            throw new ConfigurationException(sprintf('Gen::int(%d, %d): min exceeds max.', $min, $max));
        }

        // PHP integer overflow degrades to float, so test the width
        // before subtracting (rector note: an is_int() check on the
        // difference gets "simplified" away — its inference does not
        // model overflow).
        if ($min < 0 && $max > PHP_INT_MAX + $min) {
            throw new ConfigurationException('Gen::int(): the range overflows; use a narrower one.');
        }

        $range = $max - $min;

        return new self(static fn(ChoiceSource $source): int => self::intFromChoice($source->draw($range), $min, $max));
    }

    /**
     * @return self<bool>
     */
    public static function bool(): self
    {
        return new self(static fn(ChoiceSource $source): bool => $source->draw(1) === 1);
    }

    /**
     * Finite floats in [$min, $max] (D-040, refined by D-069).
     * Structured for shrinking: a leading choice picks between the
     * in-range *specials* pool (0.0, 1.0, -1.0, the bounds — earlier
     * is simpler) and the continuum. A range wider than one unit
     * encodes as integer part (riding the int ordering, toward zero)
     * plus a dyadic n/65536 fraction that shrinks toward whole
     * numbers; anything narrower uses 65,536 evenly spaced points, so
     * resolution scales with the range instead of collapsing when it
     * drops under the fraction quantum (the D-069 probe: [0, 1e-6]
     * produced two distinct values and could never falsify). Non-
     * finite edge cases (NAN, ±INF) are never produced by a bounded
     * generator — compose them explicitly: `Gen::oneOf(
     * Gen::elementOf([NAN, INF, -INF]), Gen::float(...))`.
     *
     * @return self<float>
     */
    public static function float(float $min = -1_000.0, float $max = 1_000.0): self
    {
        if (!is_finite($min) || !is_finite($max) || $min > $max) {
            throw new ConfigurationException(sprintf('Gen::float(%s, %s): needs a finite range with min <= max.', (string) $min, (string) $max));
        }

        $specials = [];

        foreach ([0.0, 1.0, -1.0, $min, $max] as $candidate) {
            if ($candidate >= $min && $candidate <= $max && !in_array($candidate, $specials, true)) {
                $specials[] = $candidate;
            }
        }

        if ($specials === []) {
            $specials = [$min]; // unreachable ($min always qualifies), but provable
        }

        // Both whole-part bounds FLOOR (not ceil/floor): the fraction
        // only adds, so flooring $min keeps the sub-integer interior
        // below the first whole number reachable ([-0.5, 0.5] used to
        // have no way to produce an interior negative), and the clamp
        // still guarantees the bounds.
        $intMin = (int) floor($min);
        $intMax = (int) floor($max);

        $continuum = $max - $min > 1.0
            ? static function (ChoiceSource $source) use ($min, $max, $intMin, $intMax): float {
                $whole    = self::intFromChoice($source->draw($intMax - $intMin), $intMin, $intMax);
                $fraction = $source->draw(65_535) / 65_536.0;

                return max($min, min($max, $whole + $fraction));
            }
        : static fn(ChoiceSource $source): float => $min + ($max - $min) * ($source->draw(65_535) / 65_535.0);

        return new self(static function (ChoiceSource $source) use ($specials, $continuum): float {
            // Choice 0 = the specials pool = simplest; shrinking any
            // continuum float first tries to land on a named value.
            if ($source->draw(7) === 0) {
                return $specials[$source->draw(count($specials) - 1)];
            }

            return $continuum($source);
        });
    }

    /**
     * @template TValue
     *
     * @param TValue $value
     *
     * @return self<TValue>
     */
    public static function constant(mixed $value): self
    {
        return new self(static fn(ChoiceSource $source): mixed => $value);
    }

    /**
     * One of the given values; earlier entries are simpler.
     *
     * @template TValue
     *
     * @param list<TValue> $values
     *
     * @return self<TValue>
     */
    public static function elementOf(array $values): self
    {
        if ($values === []) {
            throw new ConfigurationException('Gen::elementOf() needs a non-empty list.');
        }

        return new self(static fn(ChoiceSource $source): mixed => $values[$source->draw(count($values) - 1)]);
    }

    /**
     * Delegates to one of the given generators; earlier ones are
     * simpler. The result produces the union of what they produce.
     *
     * @template TValue
     *
     * @param self<TValue> ...$generators
     *
     * @return self<TValue>
     */
    public static function oneOf(self ...$generators): self
    {
        $generators = array_values($generators);

        if ($generators === []) {
            throw new ConfigurationException('Gen::oneOf() needs at least one generator.');
        }

        $last = count($generators) - 1;

        return new self(static fn(ChoiceSource $source): mixed => $generators[$source->draw($last)]->generate($source));
    }

    /**
     * Strings over the alphabet, shrinking toward '' and toward the
     * alphabet's first character.
     *
     * @param int<0, max>      $maxLength
     * @param non-empty-string $alphabet
     *
     * @return self<string>
     */
    public static function string(
        int $maxLength = 20,
        string $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 _-',
    ): self {
        $characters = mb_str_split($alphabet);
        $last       = count($characters) - 1;

        // Continuation choices, not a length prefix (the Hypothesis
        // list encoding): each element is preceded by a "one more?"
        // choice, so deleting an element's choices from the stream
        // deletes the element — no stale length drawing phantom
        // entries. The choice is biased (0 of 8 stops) or lengths
        // would collapse to a coin-flip average of one.
        return new self(static function (ChoiceSource $source) use ($maxLength, $characters, $last): string {
            $string = '';
            $length = 0;

            while ($length < $maxLength && $source->draw(7) !== 0) {
                $string .= $characters[$source->draw($last)];
                $length++;
            }

            return $string;
        });
    }

    /**
     * Lists of up to $maxCount elements, shrinking toward [].
     *
     * @template TElement
     *
     * @param self<TElement> $element
     * @param int<0, max>    $maxCount
     *
     * @return self<list<TElement>>
     */
    public static function listOf(self $element, int $maxCount = 25): self
    {
        // Continuation-choice encoding — see string() for why.
        return new self(static function (ChoiceSource $source) use ($element, $maxCount): array {
            $items = [];

            while (count($items) < $maxCount && $source->draw(7) !== 0) {
                $items[] = $element->generate($source);
            }

            return $items;
        });
    }

    /**
     * The transformed generator — the D-051 generics paying the bill
     * this docblock once deferred.
     *
     * @template TOut
     *
     * @param Closure(T): TOut $transform
     *
     * @return self<TOut>
     */
    public function map(Closure $transform): self
    {
        $build = $this->build;

        return new self(static fn(ChoiceSource $source): mixed => $transform($build($source)));
    }

    /**
     * Keeps drawing until the predicate holds. A filter that rejects
     * too much is a configuration problem, reported as such — the
     * property runner discards the case, it never fails the test.
     *
     * @param Closure(T): bool $predicate
     * @param positive-int     $maxAttempts
     *
     * @return self<T>
     */
    public function suchThat(Closure $predicate, int $maxAttempts = 50): self
    {
        $build = $this->build;

        return new self(static function (ChoiceSource $source) use ($build, $predicate, $maxAttempts): mixed {
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $value = $build($source);

                if ($predicate($value)) {
                    return $value;
                }
            }

            throw new CannotGenerate('suchThat() rejected every candidate; loosen the filter or shape the generator.');
        });
    }

    /**
     * The choice → value ordering for integers: choice 0 is the
     * in-range value closest to zero, and successive choices
     * alternate outward (0, 1, -1, 2, -2, …) until one side of the
     * range runs out. A bijection, so every value stays reachable.
     */
    private static function intFromChoice(int $choice, int $min, int $max): int
    {
        $target = match (true) {
            $min > 0 => $min,
            $max < 0 => $max,
            default  => 0,
        };

        if ($choice === 0) {
            return $target;
        }

        $down  = $target - $min;
        $up    = $max - $target;
        $pairs = min($down, $up);

        if ($choice <= 2 * $pairs) {
            $step = intdiv($choice + 1, 2);

            return $choice % 2 === 1 ? $target + $step : $target - $step;
        }

        $rest = $choice - 2 * $pairs;

        return $up > $down ? $target + $pairs + $rest : $target - $pairs - $rest;
    }
}
