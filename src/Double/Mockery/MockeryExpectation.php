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
use InvalidArgumentException;
use LucianoPereira\Crucible\Assert\Constraint\Callback;
use LucianoPereira\Crucible\Double\DoubleState;
use LucianoPereira\Crucible\Double\InvocationCount;
use LucianoPereira\Crucible\Double\MethodConfigurator;
use Throwable;

use function array_values;
use function count;
use function get_debug_type;
use function is_array;
use function is_string;
use function min;
use function sprintf;

/**
 * The fluent surface behind shouldReceive/allows/expects — the
 * Mockery verbs as spellings over the one state brain (D-060): every
 * verb registers ordinary MethodConfigurators; the return sugar maps
 * onto their willReturn* internals; counts attach fluently through
 * expectCount. Dispatch semantics live in DoubleState's
 * FirstDeclared strategy, not here.
 */
final readonly class MockeryExpectation
{
    /**
     * @param non-empty-list<MethodConfigurator> $configurators
     */
    private function __construct(private array $configurators) {}

    /**
     * shouldReceive(...): zero-or-more by default; array entries are
     * the method => return sugar. The mock verb (expects()) chains
     * ->once() on the result; the negative (shouldNotReceive) chains
     * ->never().
     *
     * @param list<array<string, mixed>|string> $methods
     */
    public static function receiving(DoubleState $state, array $methods): self
    {
        if ($methods === []) {
            throw new MockeryException('shouldReceive() needs at least one method name (the fluent no-argument form is not part of this tier).');
        }

        $configurators = [];

        foreach ($methods as $method) {
            if (is_array($method)) {
                foreach ($method as $name => $return) {
                    if ($name === '') {
                        continue;
                    }

                    $configurator = $state->configureMethod($name);
                    $configurator->willReturn($return);
                    MockeryContainer::guardMockableMethod($state, $name);
                    $configurators[] = $configurator;
                }

                continue;
            }

            if ($method === '') {
                continue;
            }

            MockeryContainer::guardMockableMethod($state, $method);
            $configurators[] = $state->configureMethod($method);
        }

        if ($configurators === []) {
            throw new MockeryException('shouldReceive() needs at least one method name.');
        }

        return new self($configurators);
    }

    /**
     * allows(...): the stub verb — same registration, never verified.
     *
     * @param list<array<string, mixed>|string> $methods
     */
    public static function allowing(DoubleState $state, array $methods): self
    {
        return self::receiving($state, $methods);
    }

    // -- returns ----------------------------------------------------------

    /**
     * One value returns forever; several return consecutively with
     * the LAST repeating (oracle-pinned: 1,2,3 then 3,3,3...).
     */
    public function andReturn(mixed ...$values): self
    {
        return $this->returnSequence($values);
    }

    /** Grammar alias of andReturn(). */
    public function andReturns(mixed ...$values): self
    {
        return $this->returnSequence($values);
    }

    /**
     * @param list<mixed> $values
     */
    public function andReturnValues(array $values): self
    {
        return $this->returnSequence($values);
    }

    public function andReturnNull(): self
    {
        return $this->returnSequence([null]);
    }

    public function andReturnTrue(): self
    {
        return $this->returnSequence([true]);
    }

    public function andReturnFalse(): self
    {
        return $this->returnSequence([false]);
    }

    public function andReturnSelf(): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->willReturnSelf();
        }

        return $this;
    }

    public function andReturnArg(int $index): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->willReturnArgument($index);
        }

        return $this;
    }

    /**
     * @param callable(mixed...): mixed $callback
     */
    public function andReturnUsing(callable $callback): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->willReturnCallback($callback);
        }

        return $this;
    }

    /**
     * @param class-string<Throwable>|Throwable $throwable
     */
    public function andThrow(string|Throwable $throwable, string $message = ''): self
    {
        $instance = is_string($throwable) ? new $throwable($message) : $throwable;

        foreach ($this->configurators as $configurator) {
            $configurator->willThrowException($instance);
        }

        return $this;
    }

    /**
     * @param class-string<Throwable>|Throwable $throwable
     */
    public function andThrows(string|Throwable $throwable, string $message = ''): self
    {
        return $this->andThrow($throwable, $message);
    }

    // -- arguments ----------------------------------------------------------

    /**
     * Mockery equality: scalars and arrays compare loosely, objects
     * by identity (oracle-pinned, spec §4).
     */
    public function with(mixed ...$arguments): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->with(...MockeryEquality::matchers($arguments));
        }

        return $this;
    }

    public function withAnyArgs(): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->withAnyParameters();
        }

        return $this;
    }

    public function withNoArgs(): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->with();
        }

        return $this;
    }

    /**
     * Closure form: all args to one predicate, whose result must be
     * === true (truthy is refused — §4 battery 8; an arity underflow
     * is the user's own ArgumentCountError, propagated). Array form:
     * identical to with(...$args). Anything else: the oracle's own
     * InvalidArgumentException — hence the mixed parameter; the
     * validation IS the contract.
     */
    public function withArgs(mixed $argumentsOrPredicate): self
    {
        if (is_array($argumentsOrPredicate)) {
            return $this->with(...$argumentsOrPredicate);
        }

        if (!$argumentsOrPredicate instanceof Closure) {
            // The oracle's own exception, in its own words (§4 battery 8).
            throw new InvalidArgumentException(sprintf(
                'Call to Mockery\Expectation::withArgs with an invalid argument (%s), only array and closure are allowed',
                get_debug_type($argumentsOrPredicate),
            ));
        }

        foreach ($this->configurators as $configurator) {
            $configurator->withArgumentList(new Callback(
                static fn(mixed $arguments): bool => is_array($arguments) && $argumentsOrPredicate(...$arguments) === true,
                'matches the withArgs() predicate',
            ));
        }

        return $this;
    }

    /**
     * Every given value present among the args, any position, STRICT
     * (§4 battery 8); the zero-argument form matches any call.
     */
    public function withSomeOfArgs(mixed ...$values): self
    {
        if ($values === []) {
            return $this->withAnyArgs();
        }

        foreach ($this->configurators as $configurator) {
            $configurator->withArgumentList(new ContainsValues(array_values($values), strict: true));
        }

        return $this;
    }

    // -- counts (all settle at close — the grammar's rule) --------------------

    public function once(): self
    {
        return $this->countedAs(InvocationCount::once());
    }

    public function twice(): self
    {
        return $this->countedAs(InvocationCount::exactly(2));
    }

    public function times(int $count): self
    {
        return $this->countedAs(InvocationCount::exactly($count));
    }

    public function never(): self
    {
        return $this->countedAs(InvocationCount::never());
    }

    public function zeroOrMoreTimes(): self
    {
        return $this->countedAs(InvocationCount::any());
    }

    public function between(int $minimum, int $maximum): self
    {
        return $this->countedAs(InvocationCount::between($minimum, $maximum));
    }

    /** `atLeast()->once()` / `->times(3)` — the two-step spelling. */
    public function atLeast(): CountBound
    {
        return new CountBound($this, InvocationCount::atLeast(...));
    }

    /** `atMost()->twice()` — the mirror bound. */
    public function atMost(): CountBound
    {
        return new CountBound($this, InvocationCount::atMost(...));
    }

    /** Applies a bound count (CountBound's callback target). */
    public function countedAs(InvocationCount $count): self
    {
        foreach ($this->configurators as $configurator) {
            $configurator->expectCount($count);
        }

        return $this;
    }

    /**
     * @param array<mixed> $values variadics may carry named keys
     */
    private function returnSequence(array $values): self
    {
        foreach ($this->configurators as $configurator) {
            if (count($values) <= 1) {
                $configurator->willReturn($values[0] ?? null);

                continue;
            }

            $call = 0;
            $configurator->willReturnCallback(function () use (&$call, $values): mixed {
                $value = $values[min($call, count($values) - 1)];
                $call++;

                return $value;
            });
        }

        return $this;
    }
}
