<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

/**
 * The spec's fluent double builder (D-046): getMockBuilder() returns
 * one, the knobs accumulate, getMock() builds. Defaults follow the
 * observed spec behavior — the original constructor and clone run
 * unless disabled, every doubleable method is doubled unless
 * onlyMethods() narrows the set, unconfigured calls fabricate
 * type-derived return values unless disabled. Stubs are mocks in
 * Crucible (D-017), so this one class is also the stub builder:
 * getStub()/setStubClassName() are the TestStubBuilder spellings.
 *
 * @template T of object
 */
final class MockBuilder
{
    /** @var ?list<non-empty-string> */
    private ?array $onlyMethods = null;

    private bool $callOriginalConstructor = true;

    /** @var ?list<mixed> */
    private ?array $constructorArguments = null;

    private bool $callOriginalClone = true;

    private bool $autoReturnValues = true;

    /** @var ?non-empty-string */
    private ?string $className = null;

    /**
     * @param class-string<T> $type
     */
    public function __construct(
        private readonly TestDoubles $doubles,
        private readonly string $type,
    ) {}

    /**
     * @param list<non-empty-string> $methods listed methods are doubled,
     *                                        everything else stays real
     */
    public function onlyMethods(array $methods): static
    {
        $this->onlyMethods = $methods;

        return $this;
    }

    /**
     * @param list<mixed> $arguments
     */
    public function setConstructorArgs(array $arguments): static
    {
        $this->constructorArguments = $arguments;

        return $this;
    }

    public function enableOriginalConstructor(): static
    {
        $this->callOriginalConstructor = true;

        return $this;
    }

    public function disableOriginalConstructor(): static
    {
        $this->callOriginalConstructor = false;

        return $this;
    }

    public function enableOriginalClone(): static
    {
        $this->callOriginalClone = true;

        return $this;
    }

    public function disableOriginalClone(): static
    {
        $this->callOriginalClone = false;

        return $this;
    }

    public function enableAutoReturnValueGeneration(): static
    {
        $this->autoReturnValues = true;

        return $this;
    }

    public function disableAutoReturnValueGeneration(): static
    {
        $this->autoReturnValues = false;

        return $this;
    }

    /**
     * @param non-empty-string $name
     */
    public function setMockClassName(string $name): static
    {
        $this->className = $name;

        return $this;
    }

    /**
     * @param non-empty-string $name
     */
    public function setStubClassName(string $name): static
    {
        return $this->setMockClassName($name);
    }

    /**
     * @return T&Mocked
     */
    public function getMock(): object
    {
        /** @var T&Mocked */
        return $this->doubles->fromSpecification($this->specification(), advisesExpectations: true);
    }

    /**
     * @return T&Mocked
     */
    public function getStub(): object
    {
        /** @var T&Mocked */
        return $this->doubles->fromSpecification($this->specification());
    }

    private function specification(): DoubleSpecification
    {
        return new DoubleSpecification(
            [$this->type],
            $this->onlyMethods,
            $this->callOriginalConstructor,
            $this->constructorArguments,
            $this->callOriginalClone,
            $this->autoReturnValues,
            $this->className,
            mergeConfigurations: true,
            prefixArgumentMatching: true,
        );
    }
}
