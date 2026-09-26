<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Property;

use LucianoPereira\Crucible\Types\TypeExpression;

use function count;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * A TypeExpression compiled into generators (D-132) — `Gen::of()`'s
 * insides. Every type maps onto the combinators Gen already has, so what
 * is drawn shrinks the way everything else does: ranges toward the value
 * nearest zero, strings and lists toward the shortest allowed, optional
 * keys toward absent, unions toward their first member.
 *
 * @internal
 */
final class TypeGen
{
    private const int LIMIT = 1_000_000;

    /**
     * @return Gen<mixed>
     */
    public static function compile(TypeExpression $type): Gen
    {
        return match ($type->kind) {
            'union'   => Gen::oneOf(...self::each($type->children)),
            'literal' => Gen::constant($type->literal),
            'keyword' => self::keyword($type->name, $type),
            'range'   => Gen::int($type->min ?? -self::LIMIT, $type->max ?? self::LIMIT),
            'shape'   => self::shape($type),
            'array'   => self::array($type),
            default   => throw new CannotGenerate(sprintf('Gen::of() cannot draw values of %s: there is no sound way to make one.', $type->describe())),
        };
    }

    /**
     * @param list<TypeExpression> $types
     *
     * @return list<Gen<mixed>>
     */
    private static function each(array $types): array
    {
        $generators = [];

        foreach ($types as $type) {
            $generators[] = self::compile($type);
        }

        return $generators;
    }

    /**
     * @return Gen<mixed>
     */
    private static function keyword(string $name, TypeExpression $type): Gen
    {
        return match (strtolower($name)) {
            'mixed'                             => Gen::oneOf(Gen::int(), Gen::string(), Gen::bool(), Gen::constant(null)),
            'null', 'void'                      => Gen::constant(null),
            'bool', 'boolean'                   => Gen::bool(),
            'true'                              => Gen::constant(true),
            'false'                             => Gen::constant(false),
            'int', 'integer'                    => Gen::int(),
            'positive-int'                      => Gen::int(1, self::LIMIT),
            'negative-int'                      => Gen::int(-self::LIMIT, -1),
            'non-negative-int'                  => Gen::int(0, self::LIMIT),
            'non-positive-int'                  => Gen::int(-self::LIMIT, 0),
            'non-zero-int'                      => Gen::oneOf(Gen::int(1, self::LIMIT), Gen::int(-self::LIMIT, -1)),
            'float', 'double'                   => Gen::float(),
            'string'                            => Gen::string(),
            'non-empty-string'                  => Gen::string(minLength: 1),
            'non-falsy-string', 'truthy-string' => Gen::string(alphabet: 'abcdefghijklmnopqrstuvwxyz', minLength: 1),
            'numeric-string'                    => Gen::int()->map(static fn(int $n): string => (string) $n),
            'lowercase-string'                  => Gen::string(alphabet: 'abcdefghijklmnopqrstuvwxyz0123456789 _-'),
            'numeric'                           => Gen::oneOf(Gen::int(), Gen::float()),
            'scalar'                            => Gen::oneOf(Gen::int(), Gen::string(), Gen::bool(), Gen::float()),
            'array-key'                         => Gen::oneOf(Gen::int(), Gen::string()),
            'array'                             => Gen::listOf(Gen::int()),
            'list'                              => Gen::listOf(Gen::int()),
            'non-empty-array', 'non-empty-list' => Gen::listOf(Gen::int(), minCount: 1),
            default                             => throw new CannotGenerate(sprintf('Gen::of() cannot draw values of %s: there is no sound way to make one.', $type->describe())),
        };
    }

    /**
     * @return Gen<array<array-key, mixed>>
     */
    private static function shape(TypeExpression $type): Gen
    {
        $entries = [];

        foreach ($type->keys as $key => [$valueType, $optional]) {
            $entries[$key] = [self::compile($valueType), $optional];
        }

        return Gen::shape($entries);
    }

    /**
     * array<V>, array<K, V>, list<V>, non-empty-*: lists of values, or of
     * key/value pairs made into an array. Duplicate keys collapse, as they
     * would in any array.
     *
     * @return Gen<mixed>
     */
    private static function array(TypeExpression $type): Gen
    {
        $shape    = $type->name;
        $minCount = $shape === 'non-empty-array' || $shape === 'non-empty-list' ? 1 : 0;

        if ($shape === 'iterable') {
            throw new CannotGenerate('Gen::of() cannot draw an iterable: say array<…> or list<…>.');
        }

        if (count($type->children) === 1 || $shape === 'list' || $shape === 'non-empty-list') {
            return Gen::listOf(self::compile($type->children[count($type->children) - 1]), minCount: $minCount);
        }

        $pair = Gen::shape([self::compile($type->children[0]), self::compile($type->children[1])]);

        $arrays = Gen::listOf($pair, minCount: $minCount)->map(static function (array $pairs): array {
            $array = [];

            foreach ($pairs as [$key, $value]) {
                // Only an int or a string can key an array, and PHP turns the
                // key '1' into the integer 1: a pair whose key is not one, or
                // would change type on the way in, is not one this type holds.
                if ((!is_int($key) && !is_string($key)) || (is_string($key) && (string) (int) $key === $key)) {
                    continue;
                }

                $array[$key] = $value;
            }

            return $array;
        });

        // Dropping keys can leave a non-empty type's array empty: drawn
        // again, and refused when no key of its type survives
        // (`non-empty-array<numeric-string, V>` holds no value at all).
        return $minCount === 0 ? $arrays : $arrays->suchThat(static fn(array $array): bool => $array !== []);
    }
}
