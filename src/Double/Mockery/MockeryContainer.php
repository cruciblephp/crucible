<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double\Mockery;

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Double\DispatchStrategy;
use LucianoPereira\Crucible\Double\DoubleCreationException;
use LucianoPereira\Crucible\Double\DoubleSpecification;
use LucianoPereira\Crucible\Double\DoubleState;
use LucianoPereira\Crucible\Double\Generator;
use LucianoPereira\Crucible\Double\ReturnDefaults;
use LucianoPereira\Crucible\Generated\GeneratedCode;
use ReflectionClass;

use function array_is_list;
use function array_map;
use function class_exists;
use function count;
use function explode;
use function interface_exists;
use function is_array;
use function is_string;
use function md5;
use function method_exists;
use function preg_match;
use function sprintf;
use function str_contains;
use function strrpos;
use function substr;
use function trait_exists;
use function trim;
use function var_export;

/**
 * The ambient Mockery world (the Snapshots/Browsing pattern): mocks
 * created anywhere in a test register here, and the runner settles
 * the container inside the same verifyTestDoubles window every other
 * double uses — `Mockery::close()` is a spelling for the settlement
 * Crucible performs natively. One Generator, one brain: a Mockery mock
 * is a generated Crucible double with the Mockery verbs baked in and
 * the FirstDeclared dispatch strategy (D-060).
 */
final class MockeryContainer
{
    private static ?Generator $generator = null;

    /** @var list<DoubleState> */
    private static array $states = [];

    private static ?MockeryConfiguration $configuration = null;

    /**
     * Mockery::mock() in its plain forms (spec §1): anonymous,
     * class/interface (declared for you when unknown — the oracle
     * does the same), comma-separated interfaces, quick-definition
     * arrays, constructor-argument lists.
     *
     * @return MockeryMock
     */
    public static function mock(mixed ...$arguments): object
    {
        $types                = [AnonymousMockBase::class];
        $traits               = [];
        $quickDefinitions     = [];
        $constructorArguments = null;

        foreach ($arguments as $argument) {
            if (is_string($argument)) {
                ['types' => $types, 'traits' => $traits] = self::resolveTypes($argument);

                continue;
            }

            if (is_array($argument)) {
                if ($argument !== [] && array_is_list($argument)) {
                    $constructorArguments = $argument;
                } else {
                    /** @var array<string, mixed> $argument */
                    $quickDefinitions = $argument;
                }

                continue;
            }

            throw new MockeryException('Mockery::mock(): proxied partials and objects are reference-tier surface (spec/mockery-api.md — built only if free).');
        }

        $specification = new DoubleSpecification(
            $types,
            callOriginalConstructor: $constructorArguments !== null,
            constructorArguments: $constructorArguments,
            mockerySurface: true,
            traits: $traits,
        );

        self::$generator ??= new Generator();
        $class      = self::$generator->classFor($specification);
        $reflection = new ReflectionClass($class);

        $double = $specification->callOriginalConstructor
            ? $reflection->newInstanceArgs($specification->constructorArguments ?? [])
            : $reflection->newInstanceWithoutConstructor();

        $state = new DoubleState(
            $double,
            $specification->types,
            new ReturnDefaults(static fn(string $target): object => self::mock($target)),
            strategy: DispatchStrategy::FirstDeclared,
        );

        $reflection->getProperty('__crucibleState')->setValue($double, $state);
        self::$states[] = $state;

        foreach ($quickDefinitions as $method => $return) {
            if ($method !== '') {
                $expectation = MockeryExpectation::receiving($state, [[$method => $return]]);

                if (self::configuration()->quickDefinitionsExpectAtLeastOnce()) {
                    $expectation->atLeast()->once();
                }
            }
        }

        if (!$double instanceof MockeryMock) {
            throw new MockeryException('The generated double is missing the Mockery surface.');
        }

        return $double;
    }

    /**
     * Settlement: every count expectation verified, the container
     * cleared — inside the verifyTestDoubles window, and again behind
     * Mockery::close() for suites that call it in tearDown.
     */
    public static function settle(): void
    {
        $states       = self::$states;
        self::$states = [];

        foreach ($states as $state) {
            try {
                $state->verifyExpectations();
            } catch (AssertionFailedError $e) {
                throw new InvalidCountException($e->getMessage(), $e->getCode(), $e);
            }
        }
    }

    /** Clears without verifying — the runner's between-tests hygiene. */
    public static function reset(): void
    {
        self::$states = [];
    }

    public static function configuration(): MockeryConfiguration
    {
        return self::$configuration ??= new MockeryConfiguration();
    }

    /**
     * The allowMockingNonExistentMethods(false) guard (spec §15):
     * refusing configuration of methods the doubled type never
     * declared, in the oracle's own words.
     *
     * @param non-empty-string $method
     */
    public static function guardMockableMethod(DoubleState $state, string $method): void
    {
        if (self::configuration()->allowsMockingNonExistentMethods()) {
            return;
        }

        foreach ($state->targets() as $target) {
            if ($target === AnonymousMockBase::class || method_exists($target, $method)) {
                return;
            }
        }

        throw new MockeryException(sprintf(
            "Mockery's configuration currently forbids mocking the method %s as it does not exist on the class or object being mocked.",
            $method,
        ));
    }

    /**
     * The types to double, and any traits to mix into the double
     * itself.
     *
     * @return array{types: non-empty-list<class-string>, traits: list<class-string>}
     */
    private static function resolveTypes(string $spec): array
    {
        $names  = array_map(trim(...), explode(',', $spec));
        $types  = [];
        $traits = [];

        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            $name = trim($name, '\\');

            if (trait_exists($name)) {
                // ✓ Measured against the real mockery: given a trait
                // name it returns a generated class that USES the
                // trait — class_uses() holds it, its concrete methods
                // stay real (an expectation on one does not replace
                // it), and only the rest is doubled. Declaring a class
                // under the trait's own name instead was an
                // uncatchable "Cannot redeclare trait" fatal.
                /** @var class-string $name */
                $traits[] = $name;
                $name     = self::classUsing($name);
            } elseif (!class_exists($name) && !interface_exists($name)) {
                self::declareEmptyClass($name);
            }

            /** @var class-string $name */
            $types[] = $name;
        }

        if ($types === []) {
            throw new MockeryException(sprintf('Mockery::mock(): no mockable type in "%s".', $spec));
        }

        if (count($types) > 1) {
            foreach ($types as $type) {
                if (!interface_exists($type)) {
                    throw new MockeryException(sprintf(
                        'Mockery::mock("%s"): mixing a class with interfaces is reference-tier surface; double the interfaces or the class alone.',
                        $spec,
                    ));
                }
            }
        }

        return ['types' => $types, 'traits' => $traits];
    }

    /**
     * Unknown types are mockable (spec §1, probed: the oracle declares
     * the parent itself so instanceof holds).
     */
    /**
     * A concrete class composed of one trait, generated once per trait.
     *
     * @param non-empty-string $trait
     *
     * @return non-empty-string
     */
    private static function classUsing(string $trait): string
    {
        /** @var array<string, non-empty-string> $composed */
        static $composed = [];

        if (isset($composed[$trait])) {
            return $composed[$trait];
        }

        $name = 'CrucibleMockeryTrait_' . md5($trait);

        if (!class_exists($name, false)) {
            GeneratedCode::evaluate(
                sprintf('class %s { use \\%s; }', $name, $trait),
                'a concrete class using the trait ' . $trait,
            );
        }

        return $composed[$trait] = $name;
    }

    private static function declareEmptyClass(string $name): void
    {
        $short     = $name;
        $namespace = '';

        if (str_contains($name, '\\')) {
            $position  = strrpos($name, '\\');
            $namespace = substr($name, 0, (int) $position);
            $short     = substr($name, (int) $position + 1);
        }

        // Every segment is checked before it reaches eval(), because
        // this is the one generator whose job is to declare a name that
        // does NOT exist yet — the others interpolate names that
        // class_exists() has already vouched for. ✓ Measured before
        // this guard: mock('Injected {} echo "x"; class Tail') ran the
        // echo. A mock name is usually a literal in the author's own
        // test, but it does not have to be — a data provider, a fixture
        // or a generated schema can supply one, and then untrusted text
        // reaches the compiler.
        self::guardIdentifier($name, $short, $namespace);

        $code = $namespace === ''
            ? sprintf('class %s {}', $short)
            : sprintf('namespace %s; class %s {}', $namespace, $short);

        GeneratedCode::evaluate($code, 'the empty class ' . $name . ', which nothing declares');
    }

    /**
     * Refuse a name PHP could not have declared itself.
     *
     * The pattern is PHP's own label grammar, applied per namespace
     * segment. Anything else is not a class name that happens to be
     * unusual — it is source text, and the only thing to do with source
     * text arriving as a name is refuse it.
     */
    private static function guardIdentifier(string $name, string $short, string $namespace): void
    {
        $label    = '/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/';
        $segments = $namespace === '' ? [] : explode('\\', $namespace);

        foreach ([...$segments, $short] as $segment) {
            if (preg_match($label, $segment) === 1) {
                continue;
            }

            throw new DoubleCreationException(sprintf(
                'Cannot declare %s as a double: %s is not a valid class name, and a name is not a place '
                    . 'to put code.',
                var_export($name, true),
                var_export($segment, true),
            ));
        }
    }
}
