<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Types\Observed;

use Closure;

use function array_filter;
use function array_is_list;
use function array_keys;
use function array_slice;
use function array_unique;
use function array_values;
use function class_exists;
use function class_implements;
use function count;
use function enum_exists;
use function get_parent_class;
use function implode;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function ltrim;
use function preg_match;
use function sort;
use function str_contains;
use function strlen;
use function strspn;
use function trait_exists;
use function var_export;

/**
 * A value as the narrowest PHPStan type that names it: `positive-int`,
 * `non-empty-string`, `class-string<Foo>`, `list<…>`, `array{…}`.
 * Bounded, so a large value costs a bounded string: the first 16
 * elements of an array are read, a shape names at most 12 keys, and
 * past three levels an array is `non-empty-array<mixed>`.
 */
final class ValueTypes
{
    private const int ITEMS = 16;

    private const int SHAPE = 12;

    private const int DEPTH = 3;

    private const string NAME = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_\\';

    public static function of(mixed $value, int $depth = 0): string
    {
        return match (true) {
            $value === null           => 'null',
            is_bool($value)           => $value ? 'true' : 'false',
            is_int($value)            => $value > 0 ? 'positive-int' : ($value === 0 ? '0' : 'negative-int'),
            is_float($value)          => 'float',
            is_string($value)         => self::string($value),
            is_array($value)          => self::array($value, $depth),
            $value instanceof Closure => '\Closure',
            is_object($value)         => '\\' . self::className($value),
            default                   => 'resource',
        };
    }

    /**
     * @param list<string> $types
     */
    public static function union(array $types): string
    {
        $types = array_values(array_unique($types));
        sort($types);

        return implode('|', $types);
    }

    private static function string(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        // Only a string spelled as a class name can be one; the lookups cost more than the spelling.
        if (strspn($value, self::NAME) === strlen($value)
            && (class_exists($value, false) || interface_exists($value, false) || enum_exists($value, false) || trait_exists($value, false))
        ) {
            return 'class-string<\\' . ltrim($value, '\\') . '>';
        }

        return 'non-empty-string';
    }

    /** An anonymous class reads as what it extends or implements, as PHPStan names it. */
    private static function className(object $value): string
    {
        $class = $value::class;

        if (!str_contains($class, '@anonymous')) {
            return $class;
        }

        $parent = get_parent_class($value);

        if ($parent !== false) {
            return $parent;
        }

        $interfaces = class_implements($value);

        return $interfaces === [] ? 'object' : array_values($interfaces)[0];
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function array(array $value, int $depth): string
    {
        if ($value === []) {
            return 'array{}';
        }

        if ($depth >= self::DEPTH) {
            return array_is_list($value) ? 'non-empty-list<mixed>' : 'non-empty-array<mixed>';
        }

        $items  = array_slice($value, 0, self::ITEMS, true);
        $values = [];

        foreach ($items as $item) {
            $values[] = self::of($item, $depth + 1);
        }

        if (array_is_list($value)) {
            return 'non-empty-list<' . self::union($values) . '>';
        }

        $keys = array_keys($value);

        if (count($value) <= self::SHAPE && array_filter($keys, is_int(...)) === []) {
            $parts = [];

            foreach ($value as $key => $item) {
                $name    = preg_match('/^[A-Za-z_]\w*$/', (string) $key) === 1 ? (string) $key : var_export((string) $key, true);
                $parts[] = $name . ': ' . self::of($item, $depth + 1);
            }

            return 'array{' . implode(', ', $parts) . '}';
        }

        $kinds = [];

        foreach (array_keys($items) as $key) {
            $kinds[] = is_int($key) ? 'int' : 'string';
        }

        return 'non-empty-array<' . self::union($kinds) . ', ' . self::union($values) . '>';
    }
}
