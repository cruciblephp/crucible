<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use ReflectionClass;

/**
 * Creates configured double instances: generated class, constructor
 * bypassed, state attached. TestCase keeps one per test and verifies
 * expectations at the end of the test.
 */
final class TestDoubles
{
    private readonly Generator $generator;

    /** @var list<DoubleState> */
    private array $states = [];

    public function __construct()
    {
        $this->generator = new Generator();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $target
     *
     * @return T&Mocked
     */
    public function create(string $target): object
    {
        /** @var T&Mocked */
        return $this->fromSpecification(new DoubleSpecification(
            [$target],
            mergeConfigurations: true,
            prefixArgumentMatching: true,
        ));
    }

    /**
     * @param bool $advisesExpectations mock-flavored creation: no
     *                                  expects() by test end = advisory
     *
     * @return Mocked
     */
    public function fromSpecification(DoubleSpecification $specification, bool $advisesExpectations = false): object
    {
        $doubleClass = $this->generator->classFor($specification);
        $reflection  = new ReflectionClass($doubleClass);

        /** @var Mocked $double the generated class extends/implements the target and Mocked */
        $double = $specification->callOriginalConstructor
            ? $reflection->newInstanceArgs($specification->constructorArguments ?? [])
            : $reflection->newInstanceWithoutConstructor();

        $state = new DoubleState(
            $double,
            $specification->types,
            new ReturnDefaults(fn(string $class): object => $this->create($class)),
            $specification->onlyMethods,
            $specification->autoReturnValues,
            $advisesExpectations,
            mergeConfigurations: $specification->mergeConfigurations,
            prefixArgumentMatching: $specification->prefixArgumentMatching,
        );

        $reflection->getProperty('__crucibleState')->setValue($double, $state);

        $this->states[] = $state;

        return $double;
    }

    /**
     * The mocks that ended the test without a single expectation —
     * the spec's "consider a stub instead" advisory (D-046).
     *
     * @return list<class-string>
     */
    public function expectationlessMocks(): array
    {
        $targets = [];

        foreach ($this->states as $state) {
            $target = $state->expectationlessMockTarget();

            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return $targets;
    }

    /**
     * Verifies every expectation registered on doubles created since
     * the last reset. Throws on the first unmet expectation.
     */
    public function verify(): void
    {
        foreach ($this->states as $state) {
            $state->verifyExpectations();
        }
    }

    public function reset(): void
    {
        $this->states = [];
    }
}
