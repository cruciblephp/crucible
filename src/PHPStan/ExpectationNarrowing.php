<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\PhpDoc\TypeStringResolver;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NeverType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use Throwable;

use function array_slice;
use function count;

/**
 * What a value is after a matcher passed (D-128, D-129, D-131): one set of
 * rules for the chain's value and the subject variable, so the two can
 * never disagree. A service, because `toMatchShape()` needs PHPStan's own
 * reading of a type string.
 */
final readonly class ExpectationNarrowing
{
    /**
     * Each type matcher is an assertion the PHPUnit dialect already has
     * (D-137): `toBeString()` is `assertIsString()`. Both dialects narrow
     * through the one translation, AssertConditions (D-049), so they can
     * never read the same guarantee two ways.
     */
    private const array ASSERTION = [
        'toBeString'     => 'assertIsString',
        'toBeInt'        => 'assertIsInt',
        'toBeFloat'      => 'assertIsFloat',
        'toBeBool'       => 'assertIsBool',
        'toBeArray'      => 'assertIsArray',
        'toBeObject'     => 'assertIsObject',
        'toBeCallable'   => 'assertIsCallable',
        'toBeIterable'   => 'assertIsIterable',
        'toBeNumeric'    => 'assertIsNumeric',
        'toBeScalar'     => 'assertIsScalar',
        'toBeTrue'       => 'assertTrue',
        'toBeFalse'      => 'assertFalse',
        'toBeNull'       => 'assertNull',
        'toBeInstanceOf' => 'assertInstanceOf',
    ];

    public function __construct(private TypeStringResolver $types) {}

    /**
     * What a value is after a matcher passed, `->not` or not: the value
     * narrowed as the equivalent assertion narrows $subject — the chain's
     * `$receiver->value`, or the variable handed to `expect()`. One path
     * for both, so the chain and the variable cannot disagree.
     */
    public function afterMatcher(Type $value, string $matcher, MethodCall $call, Expr $subject, Scope $scope, bool $negated = false): Type
    {
        if ($negated) {
            $condition = $this->condition($matcher, $call, $subject);

            return $condition instanceof Expr
                ? TypeCombinator::intersect($value, $scope->filterByFalseyValue($condition)->getType($subject))
                : $value;
        }

        if ($matcher === 'toBeList') {
            return $this->listOf($value);
        }

        if ($matcher === 'toMatchShape') {
            return $this->afterShape($value, $call->getArgs()[0]->value ?? null, $scope);
        }

        $condition = $this->condition($matcher, $call, $subject);

        return $condition instanceof Expr
            ? TypeCombinator::intersect($value, $scope->filterByTruthyValue($condition)->getType($subject))
            : $value;
    }

    /**
     * The condition a matcher guarantees about $subject, or null when it
     * guarantees nothing about its type.
     */
    public function condition(string $matcher, MethodCall $call, Expr $subject): ?Expr
    {
        $assertion = self::ASSERTION[$matcher] ?? null;

        if ($assertion === null) {
            return null;
        }

        $args = $matcher === 'toBeInstanceOf'
            ? [...array_slice($call->getArgs(), 0, 1), new Arg($subject)]
            : [new Arg($subject)];

        return AssertConditions::condition($assertion, $args);
    }

    /**
     * What an assertion statement specifies, for `$this->assert…()`,
     * `self::assert…()` and `Assert::assert…()` alike (D-049). Two
     * assertions say what no condition can: `assertMatchesShape()` names a
     * type (D-131), and `assertIsList()` reads as `toBeList()` does —
     * both overwrite with the type already computed from the value.
     *
     * @param array<Arg> $args
     */
    public function afterAssertion(string $method, array $args, Scope $scope, TypeSpecifier $typeSpecifier): SpecifiedTypes
    {
        $positional = AssertConditions::positional($args) ?? [];

        $value = match ($method) {
            'assertMatchesShape' => $positional[1] ?? null,
            'assertIsList'       => $positional[0] ?? null,
            default              => null,
        };

        if ($value instanceof Arg) {
            $type = $method === 'assertIsList'
                ? $this->listOf($scope->getType($value->value))
                : $this->afterShape($scope->getType($value->value), $positional[0]->value, $scope);

            return $typeSpecifier->create($value->value, $type, TypeSpecifierContext::createTruthy(), $scope)->setAlwaysOverwriteTypes();
        }

        $condition = AssertConditions::condition($method, $args);

        // No sound condition (named or unpacked arguments): nothing.
        return $condition instanceof Expr
            ? $typeSpecifier->specifyTypesInCondition($scope, $condition, TypeSpecifierContext::createTruthy())
            : new SpecifiedTypes();
    }

    /** Whether afterAssertion() reads $method. */
    public static function readsAssertion(string $method): bool
    {
        return $method === 'assertMatchesShape' || AssertConditions::supports($method);
    }

    /**
     * `toBeList()` and `assertIsList()`: the value, intersected with list, as
     * PHPStan reads `is_array($v) && array_is_list($v)` (D-158). A type that
     * admits the empty array keeps it: `[]` is a list and a string-keyed
     * array at once, so PHPStan before phpstan-src#6800 answered never where
     * the answer is `array{}`. One reading for both dialects, on any PHPStan.
     */
    public function listOf(Type $value): Type
    {
        $list = TypeCombinator::intersect(new ArrayType(IntegerRangeType::fromInterval(0, null), new MixedType()), new AccessoryArrayListType(), $value);
        $none = new ConstantArrayType([], []);

        if ($list instanceof NeverType && !$value->isSuperTypeOf($none)->no()) {
            return $none;
        }

        return $list;
    }

    /**
     * $value after a shape check passed: the value, narrowed to what the
     * type string names; unchanged when the string does not read.
     */
    private function afterShape(Type $value, ?Expr $shape, Scope $scope): Type
    {
        $type = $this->shapeOf($shape, $scope);

        return $type instanceof Type ? TypeCombinator::intersect($value, $type) : $value;
    }

    /**
     * The type a PHPStan type string names, when the argument is one
     * constant string that reads: `toMatchShape('array{id: int}')`,
     * `assertMatchesShape('list<int>', $v)`. Names are read as fully
     * qualified, as the run-time check reads them. Null when the string
     * is not constant or does not read — the run-time check then refuses
     * it, and nothing is narrowed.
     */
    public function shapeOf(?Expr $argument, Scope $scope): ?Type
    {
        if (!$argument instanceof \PhpParser\Node\Expr) {
            return null;
        }

        $strings = $scope->getType($argument)->getConstantStrings();

        if (count($strings) !== 1) {
            return null;
        }

        try {
            return $this->types->resolve($strings[0]->getValue());
        } catch (Throwable) {
            return null;
        }
    }
}
