<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use Closure;
use ReflectionMethod;
use ReflectionType;

use function array_key_exists;
use function array_reverse;
use function count;
use function method_exists;
use function sprintf;

/**
 * The per-double brain: registered configurators, the call log, and
 * dispatch. Generated double methods forward every call here. Knows
 * which methods the specification doubled — configuring anything
 * else is a test error, named at configuration time — and whether
 * unconfigured calls fabricate return values or refuse (the spec's
 * auto-return-value-generation switch).
 */
final class DoubleState
{
    /** @var list<MethodConfigurator> */
    private array $configurators = [];

    /** @var list<array{non-empty-string, list<mixed>}> */
    private array $calls = [];

    /**
     * Resolved once per method name; a null entry means "resolved, and
     * there is no declared type".
     *
     * @var array<non-empty-string, ?ReflectionType>
     */
    private array $returnTypes = [];

    /**
     * The default-return thunk a configurator calls only when it carries
     * no behavior of its own. Built once per method rather than once per
     * dispatch: it closes over nothing that varies, now that the double
     * is the state's own.
     *
     * @var array<non-empty-string, Closure(): mixed>
     */
    private array $fallbacks = [];

    /**
     * @param object                       $double              the one double this state belongs to;
     *                                                          both construction sites build it
     *                                                          first and attach the state to it,
     *                                                          so holding it here makes
     *                                                          one-state-one-double structural
     *                                                          rather than a convention
     * @param non-empty-list<class-string> $targets
     * @param ?list<non-empty-string>      $configurable        null = every doubled method
     * @param bool                         $advisesExpectations mock-flavored creation: ending
     *                                                          the test without expects() is
     *                                                          worth a notice (D-046)
     * @param DispatchStrategy             $strategy            which grammar's dispatch
     *                                                          semantics govern this double
     * @param bool                         $mergeConfigurations LatestWins-only: a matching
     *                                                          configurator with no return
     *                                                          behavior of its own falls back
     *                                                          through older matches instead of
     *                                                          fabricating a default (D-046's
     *                                                          "latest wins" stays the literal
     *                                                          default; see DoubleSpecification)
     * @param bool                         $prefixArgumentMatching with() constraints match as a
     *                                                             positional prefix, not exact
     *                                                             arity — see DoubleSpecification
     */
    public function __construct(
        private readonly object $double,
        private readonly array $targets,
        private readonly ReturnDefaults $defaults,
        private readonly ?array $configurable = null,
        private readonly bool $autoReturnValues = true,
        private readonly bool $advisesExpectations = false,
        private readonly DispatchStrategy $strategy = DispatchStrategy::LatestWins,
        private readonly bool $mergeConfigurations = false,
        private readonly bool $prefixArgumentMatching = false,
    ) {}

    /**
     * @return non-empty-list<class-string>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    /**
     * @return ?class-string the doubled type, when this double is a
     *                       mock that ended up with no expectations
     */
    public function expectationlessMockTarget(): ?string
    {
        if (!$this->advisesExpectations) {
            return null;
        }

        foreach ($this->configurators as $configurator) {
            if ($configurator->isExpectation()) {
                return null;
            }
        }

        return $this->targets[0];
    }

    /**
     * @param non-empty-string $method
     */
    public function configureMethod(string $method): MethodConfigurator
    {
        $configurator = (new MethodConfigurator(null, $this->configurable, $this->prefixArgumentMatching))->method($method);

        $this->configurators[] = $configurator;

        return $configurator;
    }

    public function expectInvocation(InvocationCount $count): MethodConfigurator
    {
        $configurator = new MethodConfigurator($count, $this->configurable, $this->prefixArgumentMatching);

        $this->configurators[] = $configurator;

        return $configurator;
    }

    /**
     * The state of a double, or a refusal naming what was called.
     *
     * Generated code holds its state in a nullable property, because
     * the double is instantiated first and given its state after. Every
     * generated method and every method of the configuration surface
     * therefore dereferences a nullable, which is exactly what the
     * generated-code type tier reported — 39 times, all true. Left
     * alone, a double built outside TestDoubles answers a method call
     * with "Call to a member function dispatch() on null", which names
     * neither the double nor the method.
     */
    public static function of(?self $state, object $double, string $method): self
    {
        return $state ?? throw new DoubleConfigurationException(sprintf(
            '%s::%s() was called on a double with no state attached. Doubles are created through '
                . 'TestDoubles, which attaches it; one built any other way cannot answer anything.',
            $double::class,
            $method,
        ));
    }

    /**
     * @param non-empty-string $method
     * @param list<mixed>      $arguments
     */
    public function dispatch(object $double, string $method, array $arguments): mixed
    {
        $this->calls[] = [$method, $arguments];

        if ($this->strategy === DispatchStrategy::FirstDeclared) {
            return $this->dispatchFirstDeclared($double, $method, $arguments);
        }

        // Latest configuration wins, so the walk is newest-first.
        //
        // Without merging — the default — only the newest match is ever
        // used, so the loop stops there. It used to array_reverse() the
        // whole list and collect every match into a second array, two
        // allocations per call to read one element, and every later
        // configurator was asked appliesTo() for an answer nobody read.
        if (!$this->mergeConfigurations) {
            for ($index = count($this->configurators) - 1; $index >= 0; $index--) {
                $configurator = $this->configurators[$index];

                if (!$configurator->appliesTo($method, $arguments)) {
                    continue;
                }

                $configurator->registerInvocation();

                return $configurator->invoke(
                    $double,
                    $arguments,
                    $this->fallbackFor($method),
                );
            }

            return $this->defaultReturn($double, $method);
        }

        // Merging reads every match, so it still collects them all,
        // newest-first. ✓ Measured: an index loop over the same list is
        // slower here than the reverse-and-walk, so this branch keeps
        // what it had.
        $matching = [];

        foreach (array_reverse($this->configurators) as $configurator) {
            if ($configurator->appliesTo($method, $arguments)) {
                $matching[] = $configurator;
            }
        }

        if ($matching === []) {
            return $this->defaultReturn($double, $method);
        }

        // Real-world code commonly layers a bare expects()->method()
        // invocation-count expectation over a return value configured
        // earlier via a shared helper. Every matching configurator still
        // tracks its own count (each is independent, spec-accurate), but
        // the return value comes from the newest one that actually
        // carries a behavior — not from an emptier configurator that
        // would otherwise shadow it.
        foreach ($matching as $configurator) {
            $configurator->registerInvocation();
        }

        foreach ($matching as $configurator) {
            if ($configurator->hasBehavior()) {
                return $configurator->invoke(
                    $double,
                    $arguments,
                    $this->fallbackFor($method),
                );
            }
        }

        return $this->defaultReturn($double, $method);
    }

    /**
     * The Mockery grammar's dispatch, oracle-pinned (D-060 /
     * spec/mockery-api.md §3): first-declared expectation wins, a
     * count-exhausted one falls through to the next match, an
     * exhausted one with no successor still handles the call (its
     * violation settles at close, never at call time), and an
     * unmatched call is a NAMED error — unknown method vs
     * non-matching arguments are different mistakes.
     *
     * @param non-empty-string $method
     * @param list<mixed>      $arguments
     */
    private function dispatchFirstDeclared(object $double, string $method, array $arguments): mixed
    {
        $fallback = null;

        foreach ($this->configurators as $configurator) {
            if (!$configurator->appliesTo($method, $arguments)) {
                continue;
            }

            if (!$configurator->exhausted()) {
                $configurator->registerInvocation(failFast: false);

                return $configurator->invoke($double, $arguments, $this->fallbackFor($method));
            }

            $fallback ??= $configurator;
        }

        if ($fallback instanceof MethodConfigurator) {
            $fallback->registerInvocation(failFast: false);

            return $fallback->invoke($double, $arguments, $this->fallbackFor($method));
        }

        foreach ($this->configurators as $configurator) {
            if ($configurator->methodName() === $method) {
                throw new Mockery\NoMatchingExpectationException(sprintf(
                    'No matching handler found for %s::%s(). Either the method was unexpected or its arguments matched no expected argument list for this method.',
                    $this->targets[0],
                    $method,
                ));
            }
        }

        throw new Mockery\MockeryBadMethodCallException(sprintf(
            'Method %s::%s() does not exist on this mock object.',
            $this->targets[0],
            $method,
        ));
    }

    /**
     * @throws \LucianoPereira\Crucible\Assert\AssertionFailedError
     */
    public function verifyExpectations(): void
    {
        foreach ($this->configurators as $configurator) {
            $configurator->verify();
        }
    }

    /**
     * @param non-empty-string $method
     *
     * @return list<array{non-empty-string, list<mixed>}>
     */
    public function callsTo(string $method): array
    {
        $matching = [];

        foreach ($this->calls as $call) {
            if ($call[0] === $method) {
                $matching[] = $call;
            }
        }

        return $matching;
    }

    /**
     * @param non-empty-string $method
     */
    private function defaultReturn(object $double, string $method): mixed
    {
        if (!$this->autoReturnValues) {
            throw new DoubleConfigurationException(sprintf(
                '%s::%s() has no configured return value and automatic return value generation is disabled.',
                $this->targets[0],
                $method,
            ));
        }

        // Memoised per method, and the TYPE rather than the
        // ReflectionMethod, because the type is all this reads. A fresh
        // ReflectionMethod per dispatch is why a double with ZERO
        // configurators already cost many times a plain object.
        // array_key_exists, not ??=: a method with no declared return
        // type resolves to null and must not be resolved again.
        if (!array_key_exists($method, $this->returnTypes)) {
            $reflection = $this->reflectionOf($method);

            // Tentative return types are invisible to getReturnType() —
            // Countable::count(): int reads as null there. The generated
            // SIGNATURE already falls back (Generator::methodCode), so
            // without the same fallback here the double promises int and
            // answers null: ✓ measured, doubling Countable, ArrayAccess or
            // Iterator produced a TypeError on the first call.
            $this->returnTypes[$method] = $reflection?->getReturnType() ?? $reflection?->getTentativeReturnType();
        }

        return $this->defaults->forType($this->returnTypes[$method], $double);
    }

    /**
     * @param non-empty-string $method
     *
     * @return Closure(): mixed
     */
    private function fallbackFor(string $method): Closure
    {
        return $this->fallbacks[$method] ??= fn(): mixed => $this->defaultReturn($this->double, $method);
    }

    /**
     * @param non-empty-string $method
     */
    private function reflectionOf(string $method): ?ReflectionMethod
    {
        foreach ($this->targets as $target) {
            if (method_exists($target, $method)) {
                return new ReflectionMethod($target, $method);
            }
        }

        // Mockery-surface doubles accept methods no target declares
        // (anonymous mocks, allowMockingNonExistentMethods) — untyped,
        // so the default is null.
        return null;
    }
}
