<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\IncompleteTestError;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;

use function array_is_list;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function intdiv;
use function is_array;
use function max;
use function min;
use function random_int;
use function sprintf;

/**
 * The property runner (growth G5, Hypothesis-style): N cases drawn
 * from a seeded choice stream; the first falsifying case is shrunk
 * by editing its recorded choices — delete spans, zero spans, walk
 * single choices toward 0 — and the minimal counterexample is
 * reported with the seed, so the failure replays exactly. Engine-
 * owned and dialect-neutral: it runs inside any test body, whatever
 * frontend declared it.
 *
 *     Property::forAll(Gen::int(), Gen::int())
 *         ->check(fn (int $a, int $b) => Assert::assertSame($a + $b, $b + $a));
 */
final class Property
{
    private const int SHRINK_BUDGET = 500;

    private int $cases = 100;

    private ?int $seed = null;

    /**
     * Heterogeneous by nature — mixed is the honest element type
     * here; the PHPStan extension recovers the per-position types
     * for check() closures from the call site (D-051).
     *
     * @param non-empty-list<Gen<mixed>> $generators
     */
    private function __construct(
        private readonly array $generators,
    ) {}

    /**
     * @param Gen<mixed> ...$generators
     */
    public static function forAll(Gen ...$generators): self
    {
        $generators = array_values($generators);

        if ($generators === []) {
            throw new ConfigurationException('Property::forAll() needs at least one generator.');
        }

        return new self($generators);
    }

    /**
     * @param positive-int $cases
     */
    public function cases(int $cases): self
    {
        $this->cases = $cases;

        return $this;
    }

    /**
     * Pins the random seed — the replay knob a falsification names.
     */
    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * Runs the property against every generated case. The property
     * may assert (preferred) or return false to falsify; any other
     * return value passes. Throws the shrunk counterexample as an
     * assertion failure.
     */
    public function check(callable $property): void
    {
        $context = PropertyContext::claim();

        // The failure database (D-040): stored counterexamples replay
        // before any random case, so a bug that once falsified is
        // caught on case 1 forever, whatever today's seed is.
        $sequences = $context['sequences'] ?? [];
        $clean     = $sequences !== [];

        foreach ($sequences as $stored) {
            $source = new ChoiceSource(null, $stored);

            try {
                $arguments = $this->arguments($source);
            } catch (CannotGenerate) {
                // The property changed shape; the entry is inert — and
                // an entry that could not replay was not proven fixed.
                $clean = false;

                continue;
            }

            $failure = $this->falsify($property, $arguments);

            if ($failure instanceof Throwable) {
                [$choices, $arguments, $failure, $steps] = $this->shrink($property, $source->choices(), $arguments, $failure);

                $this->fail('by the failure database', $steps, $arguments, $failure, $context['key'] ?? null, $choices);
            }
        }

        if ($clean && isset($context['key'])) {
            PropertyContext::confirmClean($context['key']);
        }

        $seed       = $this->seed ?? random_int(0, 2_147_483_647);
        $randomizer = new Randomizer(new Mt19937($seed));
        $discards   = 0;

        for ($case = 1; $case <= $this->cases; $case++) {
            $source = new ChoiceSource($randomizer);

            try {
                $arguments = $this->arguments($source);
            } catch (CannotGenerate $exhausted) {
                if (++$discards > max(100, $this->cases * 10)) {
                    throw new ConfigurationException($exhausted->getMessage(), $exhausted->getCode(), $exhausted);
                }

                $case--;

                continue;
            }

            $failure = $this->falsify($property, $arguments);

            if (!$failure instanceof Throwable) {
                continue;
            }

            [$choices, $arguments, $failure, $steps] = $this->shrink($property, $source->choices(), $arguments, $failure);

            $this->fail(
                sprintf('at case %d of %d (seed %d)', $case, $this->cases, $seed),
                $steps,
                $arguments,
                $failure,
                $context['key'] ?? null,
                $choices,
                $seed,
            );
        }

        // The cases are the assertion; without this a pure-generator
        // property would read as risky.
        Assert::countSatisfiedAssertion();
    }

    /**
     * @param non-empty-string  $origin
     * @param list<mixed>       $arguments
     * @param ?non-empty-string $key       the failure-database key, when a runner context is open
     * @param list<int>         $choices
     */
    private function fail(string $origin, int $steps, array $arguments, Throwable $failure, ?string $key, array $choices, ?int $seed = null): never
    {
        $message = sprintf(
            "Property falsified %s, %d shrink steps.\nCounterexample: %s\n%s\n\n%s",
            $origin,
            $steps,
            implode(', ', array_map(self::compact(...), $arguments)),
            $seed !== null ? sprintf('Replay with ->seed(%d).', $seed) : 'It replays on every run until fixed.',
            $failure->getMessage(),
        );

        throw $key !== null
            ? new PropertyFailedError($message, $key, $choices)
            : new AssertionFailedError($message);
    }

    /**
     * @return list<mixed>
     */
    private function arguments(ChoiceSource $source): array
    {
        return array_map(static fn(Gen $generator): mixed => $generator->generate($source), $this->generators);
    }

    /**
     * Null when the property holds; the failure otherwise. Skip and
     * incomplete signals pass through — they are verdicts about the
     * test, not the property.
     *
     * @param list<mixed> $arguments
     */
    private function falsify(callable $property, array $arguments): ?Throwable
    {
        try {
            $verdict = $property(...$arguments);
        } catch (SkippedTestError|IncompleteTestError $signal) {
            throw $signal;
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return $verdict === false
            ? new AssertionFailedError('The property returned false.')
            : null;
    }

    /**
     * Choice-sequence shrinking: candidates are edits of the failing
     * sequence — spans deleted, spans zeroed, single choices halved
     * and decremented — and a candidate replaces the incumbent when
     * it still fails and its consumed sequence is simpler (shorter,
     * or lexicographically smaller at equal length). Runs to a
     * fixpoint or the budget.
     *
     * @param list<int>   $choices
     * @param list<mixed> $arguments
     *
     * @return array{list<int>, list<mixed>, Throwable, int}
     */
    private function shrink(callable $property, array $choices, array $arguments, Throwable $failure): array
    {
        $budget = self::SHRINK_BUDGET;
        $steps  = 0;

        $improved = true;

        while ($improved && $budget > 0) {
            $improved = false;

            foreach ($this->candidates($choices) as $candidate) {
                if ($budget-- <= 0) {
                    break;
                }

                $source = new ChoiceSource(null, $candidate);

                try {
                    $candidateArguments = $this->arguments($source);
                } catch (CannotGenerate) {
                    continue;
                }

                $candidateFailure = $this->falsify($property, $candidateArguments);

                if (!$candidateFailure instanceof Throwable) {
                    continue;
                }

                $consumed = $source->choices();

                if (!$this->simpler($consumed, $choices)) {
                    continue;
                }

                $choices   = $consumed;
                $arguments = $candidateArguments;
                $failure   = $candidateFailure;
                $steps++;
                $improved = true;

                break; // restart the passes from the new incumbent
            }
        }

        return [$choices, $arguments, $failure, $steps];
    }

    /**
     * The edit passes, most aggressive first.
     *
     * @param list<int> $choices
     *
     * @return iterable<array<int, int>> contiguously indexed from 0
     */
    private function candidates(array $choices): iterable
    {
        $count = count($choices);

        // Delete spans — halves down to single choices.
        for ($span = max(1, intdiv($count, 2)); $span >= 1; $span = intdiv($span, 2)) {
            for ($at = 0; $at + $span <= $count; $at += $span) {
                yield array_merge(array_slice($choices, 0, $at), array_slice($choices, $at + $span));
            }
        }

        // Zero spans.
        for ($span = max(1, intdiv($count, 2)); $span >= 1; $span = intdiv($span, 2)) {
            for ($at = 0; $at + $span <= $count; $at += $span) {
                $zeroed = $choices;

                for ($i = $at; $i < $at + $span; $i++) {
                    $zeroed[$i] = 0;
                }

                if ($zeroed !== $choices) {
                    yield $zeroed;
                }
            }
        }

        // Redistribute-and-delete: move choice $at onto a nearby
        // choice and delete $at's span in the same candidate — the
        // only edit that can merge two list elements ([10, 90] →
        // [100]) under shortlex, because raising a choice alone is
        // never simpler but the merged sequence is strictly shorter.
        // Both span guesses are structure-blind: ($at-1, $at) covers
        // continuation-bit + value, ($at) alone covers bare values.
        foreach ($choices as $at => $choice) {
            if ($choice <= 0) {
                continue;
            }

            for ($to = max(0, $at - 8); $to < min($count, $at + 9); $to++) {
                if ($to === $at) {
                    continue;
                }

                foreach ([[$at - 1, $at], [$at, $at]] as [$from, $till]) {
                    if ($from < 0 || ($to >= $from && $to <= $till)) {
                        continue;
                    }

                    $merged = $choices;
                    $merged[$to] += $choice;

                    for ($i = $from; $i <= $till; $i++) {
                        unset($merged[$i]);
                    }

                    yield array_values($merged);
                }
            }
        }

        // Walk single choices toward zero: halve, then decrement.
        foreach ($choices as $at => $choice) {
            if ($choice > 0) {
                $halved      = $choices;
                $halved[$at] = intdiv($choice, 2);

                yield $halved;

                $decremented      = $choices;
                $decremented[$at] = $choice - 1;

                yield $decremented;
            }
        }
    }

    /**
     * One-line rendering for the counterexample: arrays inline,
     * scalars through the canonical exporter.
     */
    private static function compact(mixed $value): string
    {
        if (!is_array($value)) {
            return Exporter::describe($value);
        }

        $parts  = [];
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $parts[] = ($isList ? '' : Exporter::describe($key) . ' => ') . self::compact($item);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * @param list<int> $candidate
     * @param list<int> $incumbent
     */
    private function simpler(array $candidate, array $incumbent): bool
    {
        if (count($candidate) !== count($incumbent)) {
            return count($candidate) < count($incumbent);
        }

        foreach ($candidate as $at => $choice) {
            if ($choice !== $incumbent[$at]) {
                return $choice < $incumbent[$at];
            }
        }

        return false;
    }
}
