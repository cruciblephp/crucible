<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use Closure;
use LucianoPereira\Crucible\Assert\Constraint\ArrayHasKey;
use LucianoPereira\Crucible\Assert\Constraint\Callback;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Assert\Constraint\IsIdentical;
use LucianoPereira\Crucible\Assert\Constraint\IsInstanceOf;
use LucianoPereira\Crucible\Assert\Constraint\IsType;
use LucianoPereira\Crucible\Assert\Constraint\LogicalNot;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContains;
use LucianoPereira\Crucible\Assert\ValueType;

use function array_values;
use function sprintf;
use function strtolower;

/**
 * The class aliased to \Mockery (D-019 gating: only when the real
 * mockery/mockery is absent). The planned scope is the Pest mocking
 * chapter (spec/mockery-api.md): mock(), close(), getConfiguration(),
 * and the §4 matcher algebra — each factory a spelling over an
 * existing Constraint (D-061). Everything ditched by the scope
 * decision answers with a named error — never a silent no-op.
 */
final class MockeryApi
{
    /**
     * @return MockeryMock
     */
    public static function mock(mixed ...$arguments): object
    {
        return MockeryContainer::mock(...$arguments);
    }

    /**
     * A spelling for the settlement Crucible performs natively inside
     * verifyTestDoubles — idempotent, kept for tearDown() habits.
     */
    public static function close(): void
    {
        MockeryContainer::settle();
    }

    public static function getConfiguration(): MockeryConfiguration
    {
        return MockeryContainer::configuration();
    }

    // -- the §4 matcher algebra (strictness per matcher, battery 8) ---------

    /** Matches any single argument — arity still enforced. */
    public static function any(): Constraint
    {
        return new Callback(static fn(mixed $argument): bool => true, 'is anything');
    }

    /**
     * The is_{$type}() function-existence rule, case-insensitive;
     * any other name — class/interface or unknown — is an instanceof
     * at match time ('boolean' never matches, like the oracle).
     */
    public static function type(string $expected): Constraint
    {
        $valueType = match (strtolower($expected)) {
            'integer', 'long' => ValueType::Int,
            'double'          => ValueType::Float,
            default           => ValueType::tryFrom(strtolower($expected)),
        };

        if ($valueType instanceof ValueType) {
            return new IsType($valueType);
        }

        /** @var class-string $expected the oracle's late-binding instanceof: an unknown name simply never matches */
        return new IsInstanceOf($expected);
    }

    /**
     * The predicate result must be === true (truthy is refused) —
     * same rule as withArgs().
     */
    public static function on(Closure $predicate): Constraint
    {
        return new Callback(static fn(mixed $argument): bool => $predicate($argument) === true, 'is accepted by the on() predicate');
    }

    /**
     * @param non-empty-string $pattern
     */
    public static function pattern(string $pattern): Constraint
    {
        return new MatchesPattern($pattern);
    }

    /**
     * @param non-empty-string ...$methods
     */
    public static function ducktype(string ...$methods): Constraint
    {
        return new HasDuckType(array_values($methods));
    }

    /**
     * @param array<array-key, mixed> $part
     */
    public static function subset(array $part, bool $strict = true): Constraint
    {
        return new MatchesArraySubset($part, $strict);
    }

    /** Order-insensitive values, LOOSE (unlike hasValue). */
    public static function contains(mixed ...$values): Constraint
    {
        return new ContainsValues(array_values($values), strict: false);
    }

    public static function hasKey(int|string $key): Constraint
    {
        return new ArrayHasKey($key);
    }

    /** STRICT membership (unlike contains) — the oracle's asymmetry. */
    public static function hasValue(mixed $value): Constraint
    {
        return new TraversableContains($value);
    }

    /** By-ref capture of the argument — no PHPUnit equivalent. */
    public static function capture(mixed &$variable): Constraint
    {
        return new CapturesArgument($variable);
    }

    /** STRICT negation: not(1) matches '1'; identity for objects. */
    public static function not(mixed $value): Constraint
    {
        return new LogicalNot(new IsIdentical($value));
    }

    /** STRICT membership. */
    public static function anyOf(mixed ...$values): Constraint
    {
        return new IsAnyOf(array_values($values), strict: true);
    }

    /** LOOSE membership negated — the oracle's asymmetry, probed. */
    public static function notAnyOf(mixed ...$values): Constraint
    {
        return new LogicalNot(new IsAnyOf(array_values($values), strict: false));
    }

    /** Deprecated upstream since 0.9; a named error per §14. */
    public static function mustBe(): never
    {
        throw new MockeryException('Mockery::mustBe() is long-tail surface (spec/mockery-api.md §14 — deprecated upstream; use with() or Mockery::on()).');
    }

    public static function spy(): never
    {
        throw new MockeryException('Mockery::spy() is reference-tier surface (spec/mockery-api.md §7 — the scope decision ditched spies; built only if free).');
    }

    public static function namedMock(): never
    {
        throw new MockeryException('Mockery::namedMock() is reference-tier surface (spec/mockery-api.md §1).');
    }

    public static function instanceMock(): never
    {
        throw new MockeryException('Mockery::instanceMock() is reference-tier surface (spec/mockery-api.md §1).');
    }

    public static function globalHelpers(): never
    {
        throw new MockeryException('Mockery::globalHelpers() is long-tail surface (spec/mockery-api.md §14).');
    }

    /**
     * Everything else in the §14 long tail is a named error; a named
     * error beats a silent wrong match.
     *
     * @param list<mixed> $arguments
     */
    public static function __callStatic(string $method, array $arguments): never
    {
        throw new MockeryException(sprintf(
            'Mockery::%s() is not part of the shipped tier (spec/mockery-api.md — M2 grammar + M3 matchers are the scope; ditched and long-tail surface stays reference-only).',
            $method,
        ));
    }
}
