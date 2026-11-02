<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Double;

use Closure;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Assert\Constraint\IsEqual;
use LucianoPereira\Crucible\Assert\Constraint\IsTrue;
use ReflectionMethod;
use Throwable;

use function array_last;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function sprintf;

/**
 * The fluent configuration of one stubbed/expected method — the
 * spec's InvocationMocker surface: with(), willReturn*(),
 * willThrowException(), backed by an optional invocation-count
 * expectation.
 */
final class MethodConfigurator
{
    /** @var ?non-empty-string */
    private ?string $method = null;

    /** @var ?list<mixed> */
    private ?array $argumentMatchers = null;

    private ?Constraint $argumentListMatcher = null;

    /** @var ?Closure(list<mixed>, object): mixed */
    private ?Closure $behavior = null;

    private int $invocations = 0;

    /** The first argument mismatch reported, reported again at verification. */
    private ?string $mismatch = null;

    /**
     * @param ?list<non-empty-string>                                    $configurable           methods the double's
     *                                                                                           specification doubled; null = all
     * @param bool                                                       $prefixArgumentMatching with() constraints match as a
     *                                                                                           positional prefix, not exact arity
     *                                                                                           (off = Mockery's oracle-pinned exact
     *                                                                                           match; see DoubleSpecification)
     * @param ?Closure(non-empty-string, ?list<mixed>): string           $invocation             the PHPUnit grammar's call text,
     *                                                                                           `Class::method()` without arguments;
     *                                                                                           given, failures read as PHPUnit's
     * @param ?Closure(self): void                                       $beforeWith             the PHPUnit grammar's refusals of a
     *                                                                                           with(), asked before it is taken
     */
    public function __construct(
        private ?InvocationCount $count = null,
        private readonly ?array $configurable = null,
        private readonly bool $prefixArgumentMatching = false,
        private readonly ?Closure $invocation = null,
        private readonly ?Closure $beforeWith = null,
    ) {}

    /**
     * The Mockery grammar attaches counts fluently after creation
     * (`->once()`, `->between(2, 3)`); the PHPUnit grammar fixes them
     * at construction. Latest declaration wins, like every other
     * fluent reconfiguration.
     */
    public function expectCount(InvocationCount $count): void
    {
        $this->count = $count;
    }

    /** Count window fully consumed — the Mockery fall-through signal. */
    public function exhausted(): bool
    {
        return $this->count instanceof InvocationCount
            && $this->count->max !== null
            && $this->invocations >= $this->count->max;
    }

    /**
     * @param non-empty-string $method
     */
    public function method(string $method): self
    {
        // A method the specification left real never reaches dispatch —
        // configuring it would be a silent no-op, so it is a named
        // error instead (the spec errors here too).
        if ($this->configurable !== null && !in_array($method, $this->configurable, true)) {
            throw new DoubleConfigurationException(sprintf(
                '%s() cannot be configured: it is not among the doubled methods.',
                $method,
            ));
        }

        $this->method = $method;

        return $this;
    }

    /**
     * Argument expectations: plain values compare by the equality
     * spec; Constraint instances match directly.
     */
    public function with(mixed ...$arguments): self
    {
        if ($this->beforeWith instanceof Closure) {
            ($this->beforeWith)($this);
        }

        $this->argumentMatchers    = array_values($arguments);
        $this->argumentListMatcher = null;

        return $this;
    }

    public function withAnyParameters(): self
    {
        $this->argumentMatchers    = null;
        $this->argumentListMatcher = null;

        return $this;
    }

    /**
     * One constraint over the WHOLE argument list — the Mockery
     * grammar's withArgs($closure)/withSomeOfArgs() shape (no
     * per-index arity: the matcher owns the entire call signature).
     * Latest declaration wins, like every with() respelling.
     */
    public function withArgumentList(Constraint $matcher): self
    {
        $this->argumentListMatcher = $matcher;
        $this->argumentMatchers    = null;

        return $this;
    }

    public function willReturn(mixed $value): self
    {
        $this->behavior = static fn(array $args, object $double): mixed => $value;

        return $this;
    }

    public function willReturnSelf(): self
    {
        $this->behavior = static fn(array $args, object $double): object => $double;

        return $this;
    }

    public function willReturnArgument(int $index): self
    {
        $this->behavior = static fn(array $args, object $double): mixed => $args[$index] ?? null;

        return $this;
    }

    /**
     * @param callable(mixed...): mixed $callback
     */
    public function willReturnCallback(callable $callback): self
    {
        $this->behavior = static fn(array $args, object $double): mixed => $callback(...$args);

        return $this;
    }

    /**
     * @param list<array<int, mixed>> $map rows of arguments with the return value last
     */
    public function willReturnMap(array $map): self
    {
        $this->behavior = static function (array $args, object $double) use ($map): mixed {
            foreach ($map as $row) {
                $expected = array_slice($row, 0, -1);

                if ((new IsEqual($expected))->matches($args)) {
                    return array_last($row);
                }
            }

            return null;
        };

        return $this;
    }

    public function willReturnOnConsecutiveCalls(mixed ...$values): self
    {
        $call = 0;

        $this->behavior = static function (array $args, object $double) use (&$call, $values): mixed {
            $value = $values[$call] ?? null;
            $call++;

            return $value;
        };

        return $this;
    }

    public function willThrowException(Throwable $throwable): self
    {
        $this->behavior = static fn(array $args, object $double): mixed => throw $throwable;

        return $this;
    }

    /**
     * The legacy pairing (`->will($this->throwException($e))`) real-world
     * PHPUnit suites still use alongside the modern willThrowException()/
     * willReturn*() calls this project's own tests use — a thin adapter
     * over the same behavior shape, not a second implementation.
     */
    public function will(Stub $stub): self
    {
        $this->behavior = $stub->behavior;

        return $this;
    }

    // --- Engine side -----------------------------------------------------

    /**
     * Whether this configuration carries an invocation-count
     * expectation (came from expects(), not method()).
     */
    public function isExpectation(): bool
    {
        return $this->count instanceof InvocationCount;
    }

    /** Whether a with() constrains the arguments one by one. */
    public function hasArgumentMatchers(): bool
    {
        return $this->argumentMatchers !== null;
    }

    /** Whether a willReturn*()/will()/willThrowException() was configured. */
    public function hasBehavior(): bool
    {
        return $this->behavior instanceof Closure;
    }

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $arguments
     */
    public function appliesTo(string $method, array $arguments): bool
    {
        if ($this->method !== $method) {
            return false;
        }

        if ($this->argumentListMatcher instanceof Constraint) {
            // evaluate(..., returnResult: true), not matches() directly:
            // $this->argumentListMatcher may be a user-supplied,
            // real-PHPUnit-descended constraint (withArgumentList()'s
            // own public contract accepts any Constraint) whose own
            // matches() override is protected, matching real PHPUnit's
            // convention — calling it here, from outside the Constraint
            // hierarchy, would fatal (PHP forbids protected access
            // across unrelated classes) even though matches() itself is
            // declared validly. evaluate() is Constraint's own public
            // entry point for exactly this.
            return $this->argumentListMatcher->evaluate($arguments, '', true) === true;
        }

        if ($this->argumentMatchers === null) {
            return true;
        }

        $matcherCount = count($this->argumentMatchers);

        // The PHPUnit grammar's with() constraints are a positional
        // prefix, not a full arity match (oracle-verified against
        // PHPUnit's own Parameters rule: it only rejects a call with
        // FEWER arguments than constraints — trailing, unconstrained
        // real arguments are never checked). The Mockery grammar keeps
        // exact arity: with(any()) matching a call with an extra,
        // unmatched argument is oracle-pinned as a non-match
        // (spec/mockery-api.md).
        $arityMismatch = $this->prefixArgumentMatching
            ? $matcherCount > count($arguments)
            : $matcherCount !== count($arguments);

        if ($arityMismatch) {
            return false;
        }

        foreach ($this->argumentMatchers as $index => $matcher) {
            $constraint = $matcher instanceof Constraint ? $matcher : new IsEqual($matcher);

            // Same reasoning as appliesTo()'s argumentListMatcher branch
            // above: $matcher may be a user-supplied, real-PHPUnit-descended
            // constraint with a protected matches() override.
            if ($constraint->evaluate($arguments[$index], '', true) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * The PHPUnit grammar's failure for a call this configuration's with()
     * rejects, or null when it has no with() or accepts the call.
     * ✓ PHPUnit 13.4.3: the first rejected parameter is named, `$name` or
     * its position past the declared ones, with the constraint's own failure.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $arguments with their defaults
     */
    public function mismatch(string $method, array $arguments, ?ReflectionMethod $reflection): ?string
    {
        $matchers = $this->argumentMatchers;

        if ($this->method !== $method || $matchers === null || !$this->invocation instanceof Closure) {
            return null;
        }

        $call = ($this->invocation)($method, $arguments);

        if (count($matchers) > count($arguments)) {
            return $this->mismatch ??= sprintf("Expectation for %s failed.\nParameter count for invocation %s is too low.", ($this->invocation)($method, null), $call);
        }

        foreach ($matchers as $index => $matcher) {
            $constraint = $matcher instanceof Constraint ? $matcher : new IsEqual($matcher);

            try {
                $constraint->evaluate($arguments[$index]);
            } catch (AssertionFailedError $failure) {
                return $this->mismatch ??= sprintf(
                    "Expectation for %s failed.\nParameter %s for invocation %s does not match expected value.\n%s",
                    ($this->invocation)($method, null),
                    PhpUnitInvocation::parameter($reflection, $index),
                    $call,
                    $failure->getMessage(),
                );
            }
        }

        return null;
    }

    /**
     * @param bool        $failFast  the PHPUnit-spec posture: an exceeded maximum
     *                               fails at call time. The Mockery grammar passes
     *                               false — every count violation settles at close
     *                               (oracle-pinned, spec/mockery-api.md §5).
     * @param list<mixed> $arguments the call's, for the PHPUnit grammar's message
     *
     * @throws AssertionFailedError when the call exceeds the expected maximum
     */
    public function registerInvocation(bool $failFast = true, array $arguments = []): void
    {
        $this->invocations++;
        $this->count?->recordInvocation();

        $count = $this->count;

        if (!$failFast || !$count instanceof InvocationCount || !$count->exceededBy($this->invocations)) {
            return;
        }

        if (!$this->invocation instanceof Closure) {
            throw new AssertionFailedError(sprintf(
                '%s() was not expected to be called more than %s.',
                $this->method ?? '{method}',
                $count->description,
            ));
        }

        // ✓ PHPUnit 13.4.3 fails at the call only for an exact count;
        // at most is judged when the test ends.
        if ($count->min !== $count->max || $this->method === null) {
            return;
        }

        $call = ($this->invocation)($this->method, $arguments);

        throw new AssertionFailedError($count->max === 0
            ? sprintf('%s was not expected to be called, actually called %s.', $call, PhpUnitInvocation::times($this->invocations))
            : sprintf('%s was not expected to be called more than %s, actually called %s.', $call, $count->expected, PhpUnitInvocation::times($this->invocations)));
    }

    /** @return ?non-empty-string */
    public function methodName(): ?string
    {
        return $this->method;
    }

    /**
     * @param list<mixed>       $arguments
     * @param Closure(): mixed  $default
     */
    public function invoke(object $double, array $arguments, Closure $default): mixed
    {
        return $this->behavior instanceof Closure ? ($this->behavior)($arguments, $double) : $default();
    }

    /**
     * @throws AssertionFailedError when the expectation was not met
     */
    public function verify(): void
    {
        $count = $this->count;

        if (!$count instanceof InvocationCount) {
            return;
        }

        if ($this->mismatch !== null) {
            throw new AssertionFailedError($this->mismatch);
        }

        if (!$count->satisfiedBy($this->invocations)) {
            if ($this->invocation instanceof Closure && $this->method !== null) {
                throw new AssertionFailedError(sprintf(
                    '%s was expected to be invoked %s but was %s.',
                    ($this->invocation)($this->method, null),
                    $count->expected,
                    PhpUnitInvocation::invoked($this->invocations),
                ));
            }

            throw new AssertionFailedError(sprintf(
                '%s() was expected to be called %s, but was called %d time(s).',
                $this->method ?? '{method}',
                $count->description,
                $this->invocations,
            ));
        }

        // A met expectation is a verified assertion.
        Assert::assertThat(true, new IsTrue());
    }
}
