<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Instanceof_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Param;

use function array_key_exists;
use function array_values;
use function in_array;

/**
 * The narrowing map (D-049): each supported assert method translates
 * into the ordinary PHP condition it guarantees on success, and the
 * extension hands that condition to PHPStan's own TypeSpecifier —
 * `assertNotNull($x)` narrows exactly like `if ($x !== null)`. One
 * translation table, zero bespoke type logic: everything PHPStan
 * learns about conditions (today and in future releases) applies to
 * assertions for free.
 */
final class AssertConditions
{
    /**
     * The is-family: assertIsX($v) guarantees is_x($v).
     */
    private const array IS_FUNCTIONS = [
        'assertIsArray'    => 'is_array',
        'assertIsBool'     => 'is_bool',
        'assertIsCallable' => 'is_callable',
        'assertIsFloat'    => 'is_float',
        'assertIsInt'      => 'is_int',
        'assertIsIterable' => 'is_iterable',
        'assertIsNumeric'  => 'is_numeric',
        'assertIsObject'   => 'is_object',
        'assertIsResource' => 'is_resource',
        'assertIsScalar'   => 'is_scalar',
        'assertIsString'   => 'is_string',
    ];

    /**
     * The negated is-family: assertIsNotX($v) guarantees !is_x($v).
     */
    private const array IS_NOT_FUNCTIONS = [
        'assertIsNotArray'    => 'is_array',
        'assertIsNotBool'     => 'is_bool',
        'assertIsNotCallable' => 'is_callable',
        'assertIsNotFloat'    => 'is_float',
        'assertIsNotInt'      => 'is_int',
        'assertIsNotIterable' => 'is_iterable',
        'assertIsNotNumeric'  => 'is_numeric',
        'assertIsNotObject'   => 'is_object',
        'assertIsNotResource' => 'is_resource',
        'assertIsNotScalar'   => 'is_scalar',
        'assertIsNotString'   => 'is_string',
    ];

    private const array OTHER_METHODS = [
        'assertTrue', 'assertNotTrue', 'assertFalse', 'assertNotFalse',
        'assertNull', 'assertNotNull', 'assertEmpty', 'assertNotEmpty',
        'assertSame', 'assertNotSame', 'assertInstanceOf', 'assertNotInstanceOf',
        'assertIsList', 'assertCount', 'assertNotCount',
        'assertArrayHasKey', 'assertArrayNotHasKey',
        'assertObjectHasProperty', 'assertObjectNotHasProperty',
        'assertContainsOnlyInstancesOf',
    ];

    public static function supports(string $method): bool
    {
        return array_key_exists($method, self::IS_FUNCTIONS)
            || array_key_exists($method, self::IS_NOT_FUNCTIONS)
            || in_array($method, self::OTHER_METHODS, true);
    }

    /**
     * The arguments as a list, or null when one is named or unpacked:
     * then the position of none of them can be trusted.
     *
     * @param array<Arg> $args
     *
     * @return ?list<Arg>
     */
    public static function positional(array $args): ?array
    {
        foreach ($args as $arg) {
            if ($arg->name !== null || $arg->unpack) {
                return null;
            }
        }

        return array_values($args);
    }

    /**
     * The condition the assertion guarantees, or null when nothing
     * sound can be said (named or unpacked arguments make positional
     * reading wrong — no narrowing then, never a guess).
     *
     * @param array<Arg> $args
     */
    public static function condition(string $method, array $args): ?Expr
    {
        $args = self::positional($args);

        if ($args === null) {
            return null;
        }

        if (array_key_exists($method, self::IS_FUNCTIONS)) {
            return self::isCall(self::IS_FUNCTIONS[$method], $args);
        }

        if (array_key_exists($method, self::IS_NOT_FUNCTIONS)) {
            return self::not(self::isCall(self::IS_NOT_FUNCTIONS[$method], $args));
        }

        return match ($method) {
            'assertTrue'                    => self::comparedTo('true', $args, negated: false),
            'assertNotTrue'                 => self::comparedTo('true', $args, negated: true),
            'assertFalse'                   => self::comparedTo('false', $args, negated: false),
            'assertNotFalse'                => self::comparedTo('false', $args, negated: true),
            'assertNull'                    => self::comparedTo('null', $args, negated: false),
            'assertNotNull'                 => self::comparedTo('null', $args, negated: true),
            'assertEmpty'                   => isset($args[0]) ? new Empty_($args[0]->value) : null,
            'assertNotEmpty'                => self::not(isset($args[0]) ? new Empty_($args[0]->value) : null),
            'assertSame'                    => isset($args[1]) ? new Identical($args[0]->value, $args[1]->value) : null,
            'assertNotSame'                 => isset($args[1]) ? new NotIdentical($args[0]->value, $args[1]->value) : null,
            'assertInstanceOf'              => self::instanceOf($args),
            'assertNotInstanceOf'           => self::not(self::instanceOf($args)),
            'assertIsList'                  => self::isList($args),
            'assertCount'                   => self::count($args, negated: false),
            'assertNotCount'                => self::count($args, negated: true),
            'assertArrayHasKey'             => self::arrayHasKey($args),
            'assertArrayNotHasKey'          => self::not(self::arrayHasKey($args)),
            'assertObjectHasProperty'       => self::objectHasProperty($args),
            'assertObjectNotHasProperty'    => self::not(self::objectHasProperty($args)),
            'assertContainsOnlyInstancesOf' => self::containsOnlyInstancesOf($args),
            default                         => null,
        };
    }

    /**
     * @param array<int, Arg> $args
     */
    private static function comparedTo(string $constant, array $args, bool $negated): ?Expr
    {
        if (!isset($args[0])) {
            return null;
        }

        $constFetch = new ConstFetch(new Name($constant));

        return $negated
            ? new NotIdentical($args[0]->value, $constFetch)
            : new Identical($args[0]->value, $constFetch);
    }

    /**
     * @param non-empty-string $function
     * @param array<int, Arg>  $args
     */
    private static function isCall(string $function, array $args): ?Expr
    {
        return isset($args[0])
            ? new FuncCall(new FullyQualified($function), [new Arg($args[0]->value)])
            : null;
    }

    /**
     * assertInstanceOf($class, $actual): the class argument is an
     * expression (usually Foo::class); PHPStan narrows instanceof
     * against class-string expressions natively.
     *
     * @param array<int, Arg> $args
     */
    private static function instanceOf(array $args): ?Expr
    {
        return isset($args[1])
            ? new Instanceof_($args[1]->value, $args[0]->value)
            : null;
    }

    /**
     * assertIsList($v) guarantees is_array($v) && array_is_list($v) —
     * the pair, because array_is_list alone presumes an array.
     *
     * @param array<int, Arg> $args
     */
    private static function isList(array $args): ?Expr
    {
        $isArray = self::isCall('is_array', $args);
        $isJust  = self::isCall('array_is_list', $args);

        return $isArray instanceof Expr && $isJust instanceof Expr
            ? new BooleanAnd($isArray, $isJust)
            : null;
    }

    /**
     * assertCount($n, $haystack) guarantees count($haystack) === $n —
     * PHPStan turns that into non-empty and sized array knowledge.
     *
     * @param array<int, Arg> $args
     */
    private static function count(array $args, bool $negated): ?Expr
    {
        if (!isset($args[1])) {
            return null;
        }

        $count = new FuncCall(new FullyQualified('count'), [new Arg($args[1]->value)]);

        return $negated
            ? new NotIdentical($count, $args[0]->value)
            : new Identical($count, $args[0]->value);
    }

    /**
     * assertArrayHasKey($key, $array) accepts an array or an ArrayAccess:
     * for the array, array_key_exists() — which PHPStan reads as
     * hasOffset and non-empty; an ArrayAccess learns nothing it did not
     * already say about itself.
     *
     * @param array<int, Arg> $args
     */
    private static function arrayHasKey(array $args): ?Expr
    {
        if (!isset($args[1])) {
            return null;
        }

        return new BooleanOr(
            new Instanceof_($args[1]->value, new FullyQualified('ArrayAccess')),
            new FuncCall(new FullyQualified('array_key_exists'), [new Arg($args[0]->value), new Arg($args[1]->value)]),
        );
    }

    /**
     * assertObjectHasProperty($name, $object) guarantees
     * property_exists($object, $name).
     *
     * @param array<int, Arg> $args
     */
    private static function objectHasProperty(array $args): ?Expr
    {
        return isset($args[1])
            ? new FuncCall(new FullyQualified('property_exists'), [new Arg($args[1]->value), new Arg($args[0]->value)])
            : null;
    }

    /**
     * assertContainsOnlyInstancesOf($class, $haystack): an array whose
     * filter by `instanceof $class` is itself — PHPStan reads the
     * filtered type back onto the haystack. A Traversable is accepted by
     * the assertion and learns nothing.
     *
     * @param array<int, Arg> $args
     */
    private static function containsOnlyInstancesOf(array $args): ?Expr
    {
        if (!isset($args[1])) {
            return null;
        }

        $item   = new Variable('crucibleItem');
        $filter = new FuncCall(new FullyQualified('array_filter'), [
            new Arg($args[1]->value),
            new Arg(new ArrowFunction([
                'static' => true,
                'params' => [new Param($item)],
                'expr'   => new Instanceof_($item, $args[0]->value),
            ])),
        ]);

        return new BooleanOr(
            new Instanceof_($args[1]->value, new FullyQualified('Traversable')),
            new Identical($args[1]->value, $filter),
        );
    }

    private static function not(?Expr $condition): ?Expr
    {
        return $condition instanceof Expr ? new BooleanNot($condition) : null;
    }
}
