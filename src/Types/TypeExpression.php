<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Types;

use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function class_exists;
use function count;
use function ctype_digit;
use function enum_exists;
use function implode;
use function in_array;
use function interface_exists;
use function is_a;
use function is_array;
use function is_bool;
use function is_callable;
use function is_float;
use function is_int;
use function is_iterable;
use function is_numeric;
use function is_object;
use function is_resource;
use function is_scalar;
use function is_string;
use function ltrim;
use function preg_match;
use function sprintf;
use function str_contains;
use function strtolower;
use function substr;

/**
 * A PHPStan type string, read at run time (D-131): the one type a
 * `toMatchShape('array{id: positive-int, tags: list<string>}')` states for
 * two readers. PHPStan narrows the value to it; this checks the value
 * against it and names the first place it does not fit —
 * `$.tags[2]: expected string, got int (7)`.
 *
 * Crucible's own reading of PHPStan's syntax, not a dependency: the
 * assertion runs in any test run, and a run-time check cannot require a
 * development tool to be installed. Its agreement with PHPStan's reading
 * is held by differential tests against the real phpstan binary. Where
 * PHPStan's reading was measured rather than assumed, it is followed:
 * shapes are open (a key the shape does not name is allowed, as
 * PHPStan 2 accepts it), `float` does not accept an int.
 *
 * The subset: unions and intersections, `?T`, `T[]`, parentheses;
 * array shapes `array{key: T, key?: T, ...}`, positional
 * `array{T, U}` and `list{T, U}`; `array<V>`, `array<K, V>`,
 * `list<V>`, `non-empty-array<…>`, `non-empty-list<V>`, `iterable<…>`,
 * `int<min, max>`, `class-string<T>`; the keywords below; literals
 * (`'on'`, `42`, `1.5`); class and interface names.
 */
final readonly class TypeExpression
{
    private const array KEYWORDS = [
        'mixed', 'null', 'bool', 'boolean', 'true', 'false', 'int', 'integer', 'float', 'double', 'string', 'array',
        'list', 'iterable', 'object', 'callable', 'scalar', 'numeric', 'positive-int', 'negative-int',
        'non-negative-int', 'non-positive-int', 'non-zero-int', 'non-empty-string', 'non-falsy-string', 'truthy-string',
        'numeric-string', 'lowercase-string', 'non-empty-array', 'non-empty-list', 'array-key', 'class-string',
        'resource', 'never', 'void', 'empty',
    ];

    /**
     * Each kind carries its own part: a keyword, class, shape or array its
     * $name; a literal its $literal; a range its $min and $max; a union,
     * intersection, array or class-string its $children; a shape its $keys.
     *
     * @param non-empty-string                    $kind
     * @param list<self>                          $children
     * @param array<array-key, array{self, bool}> $keys shape entries: key => [type, optional]
     */
    private function __construct(
        public string $kind,
        public array $children = [],
        public string $name = '',
        public int|float|string|null $literal = null,
        public ?int $min = null,
        public ?int $max = null,
        public array $keys = [],
        public bool $open = true,
    ) {}

    /**
     * @throws ConfigurationException naming the position a type string stops parsing
     */
    public static function parse(string $type): self
    {
        $parser = new TypeParser($type);

        return $parser->parse();
    }

    /**
     * @internal for TypeParser
     *
     * @param list<self> $members
     */
    public static function union(array $members): self
    {
        return new self('union', $members);
    }

    /**
     * @internal for TypeParser
     *
     * @param list<self> $members
     */
    public static function intersection(array $members): self
    {
        return new self('intersection', $members);
    }

    /** @internal for TypeParser */
    public static function literal(int|float|string $value): self
    {
        return new self('literal', literal: $value);
    }

    /** @internal for TypeParser: a keyword, lowercased */
    public static function keyword(string $name): self
    {
        return new self('keyword', name: $name);
    }

    /** @internal for TypeParser */
    public static function class(string $name): self
    {
        return new self('class', name: $name);
    }

    /** @internal for TypeParser: `int<min, max>`, null for an open end */
    public static function range(?int $min, ?int $max): self
    {
        return new self('range', min: $min, max: $max);
    }

    /**
     * @internal for TypeParser: `array{…}`, `list{…}`
     *
     * @param array<array-key, array{self, bool}> $keys
     */
    public static function shape(string $name, array $keys, bool $open): self
    {
        return new self('shape', name: $name, keys: $keys, open: $open);
    }

    /**
     * @internal for TypeParser: `array<…>`, `list<V>`, `iterable<…>`, `T[]`
     *
     * @param list<self> $arguments [V] or [K, V]
     */
    public static function array(string $name, array $arguments): self
    {
        return new self('array', $arguments, $name);
    }

    /**
     * @internal for TypeParser
     *
     * @param list<self> $arguments
     */
    public static function classString(array $arguments): self
    {
        return new self('class-string', $arguments, 'class-string');
    }

    /** The first place $value does not fit, or null when it fits. */
    public function mismatch(mixed $value, string $path = '$'): ?string
    {
        return match ($this->kind) {
            'union'        => $this->unionMismatch($value, $path),
            'intersection' => $this->intersectionMismatch($value, $path),
            'literal'      => $value === $this->literal ? null : $this->expected($path, Exporter::export($this->literal), $value),
            'keyword'      => $this->keywordMismatch($value, $path),
            'class'        => $value instanceof $this->name ? null : $this->expected($path, $this->name, $value),
            'class-string' => $this->classStringMismatch($value, $path),
            'range'        => $this->rangeMismatch($value, $path),
            'shape'        => $this->shapeMismatch($value, $path),
            'array'        => $this->arrayMismatch($value, $path),
            default        => null,
        };
    }

    public function describe(): string
    {
        return match ($this->kind) {
            'union'        => implode('|', $this->descriptions()),
            'intersection' => implode('&', $this->descriptions()),
            'literal'      => Exporter::export($this->literal),
            'range'        => sprintf('int<%s, %s>', $this->min ?? 'min', $this->max ?? 'max'),
            default        => $this->name !== '' ? $this->name : $this->kind,
        };
    }

    /** @return list<string> */
    private function descriptions(): array
    {
        $parts = [];

        foreach ($this->children as $child) {
            $parts[] = $child->describe();
        }

        return $parts;
    }

    private function expected(string $path, string $type, mixed $value): string
    {
        return sprintf('%s: expected %s, got %s', $path, $type, Exporter::describe($value));
    }

    private function unionMismatch(mixed $value, string $path): ?string
    {
        foreach ($this->children as $child) {
            if ($child->mismatch($value, $path) === null) {
                return null;
            }
        }

        // TypeParser builds a union from two members or more, so no one
        // member's reason is more specific than the union's own.
        return $this->expected($path, $this->describe(), $value);
    }

    private function intersectionMismatch(mixed $value, string $path): ?string
    {
        foreach ($this->children as $child) {
            $reason = $child->mismatch($value, $path);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    private function keywordMismatch(mixed $value, string $path): ?string
    {
        $fits = match ($this->name) {
            'mixed'                             => true,
            'null', 'void'                      => $value === null,
            'bool', 'boolean'                   => is_bool($value),
            'true'                              => $value === true,
            'false'                             => $value === false,
            'int', 'integer'                    => is_int($value),
            'float', 'double'                   => is_float($value),
            'string'                            => is_string($value),
            'array'                             => is_array($value),
            'list'                              => is_array($value) && array_is_list($value),
            'iterable'                          => is_iterable($value),
            'object'                            => is_object($value),
            'callable'                          => is_callable($value),
            'scalar'                            => is_scalar($value),
            'numeric'                           => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'positive-int'                      => is_int($value) && $value > 0,
            'negative-int'                      => is_int($value) && $value < 0,
            'non-negative-int'                  => is_int($value) && $value >= 0,
            'non-positive-int'                  => is_int($value) && $value <= 0,
            'non-zero-int'                      => is_int($value) && $value !== 0,
            'non-empty-string'                  => is_string($value) && $value !== '',
            'non-falsy-string', 'truthy-string' => is_string($value) && $value !== '' && $value !== '0',
            'numeric-string'                    => is_string($value) && is_numeric($value),
            'lowercase-string'                  => is_string($value) && strtolower($value) === $value,
            'non-empty-array'                   => is_array($value) && $value !== [],
            'non-empty-list'                    => is_array($value) && $value !== [] && array_is_list($value),
            'array-key'                         => is_int($value) || is_string($value),
            'class-string'                      => is_string($value) && (class_exists($value) || interface_exists($value) || enum_exists($value)),
            'resource'                          => is_resource($value),
            'empty'                             => empty($value),
            default                             => false,
        };

        return $fits ? null : $this->expected($path, $this->name, $value);
    }

    private function classStringMismatch(mixed $value, string $path): ?string
    {
        $class = $this->children[0] ?? null;

        return is_string($value) && $class?->kind === 'class' && is_a($value, $class->name, true)
            ? null
            : $this->expected($path, $this->describe(), $value);
    }

    private function rangeMismatch(mixed $value, string $path): ?string
    {
        return is_int($value) && ($this->min === null || $value >= $this->min) && ($this->max === null || $value <= $this->max)
            ? null
            : $this->expected($path, $this->describe(), $value);
    }

    private function shapeMismatch(mixed $value, string $path): ?string
    {
        if (!is_array($value)) {
            return $this->expected($path, 'array', $value);
        }

        if ($this->name === 'list' && !array_is_list($value)) {
            return $this->expected($path, 'a list', $value);
        }

        foreach ($this->keys as $key => [$type, $optional]) {
            $at = $this->at($path, $key);

            if (!array_key_exists($key, $value)) {
                if ($optional) {
                    continue;
                }

                return sprintf('%s: missing', $at);
            }

            $reason = $type->mismatch($value[$key], $at);

            if ($reason !== null) {
                return $reason;
            }
        }

        if (!$this->open) {
            foreach (array_keys($value) as $key) {
                if (!array_key_exists($key, $this->keys)) {
                    return sprintf('%s: a key the shape does not allow', $this->at($path, $key));
                }
            }
        }

        return null;
    }

    /**
     * array<V>, array<K, V>, list<V>, non-empty-*, iterable<…>: every
     * key and value checked, the first misfit named.
     */
    private function arrayMismatch(mixed $value, string $path): ?string
    {
        $shape = $this->name;

        if ($shape === 'iterable' ? !is_iterable($value) : !is_array($value)) {
            return $this->expected($path, $shape === 'iterable' ? 'iterable' : 'array', $value);
        }

        if (in_array($shape, ['list', 'non-empty-list'], true) && is_array($value) && !array_is_list($value)) {
            return $this->expected($path, 'a list', $value);
        }

        if (str_contains($shape, 'non-empty') && $value === []) {
            return $this->expected($path, 'a non-empty ' . (str_contains($shape, 'list') ? 'list' : 'array'), $value);
        }

        [$keyType, $valueType] = count($this->children) === 2 ? $this->children : [null, $this->children[0] ?? null];

        foreach ($value as $key => $item) {
            $at = $this->at($path, $key);

            $reason = $keyType?->mismatch($key, $at . ' (key)') ?? $valueType?->mismatch($item, $at);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /** Where a key sits under $path: `$.id`, `$[0]`, and an iterable's non-scalar key exported. */
    private function at(string $path, mixed $key): string
    {
        return match (true) {
            is_int($key)    => $path . '[' . $key . ']',
            is_string($key) => $path . '.' . $key,
            default         => $path . '[' . Exporter::export($key) . ']',
        };
    }

    /** @internal for TypeParser: whether a bare name is a keyword */
    public static function isKeyword(string $name): bool
    {
        return in_array(strtolower($name), self::KEYWORDS, true);
    }

    /** @internal for TypeParser: a class name written in a type */
    public static function className(string $name): string
    {
        return ltrim($name, '\\');
    }

    /** @internal for TypeParser: an integer literal's value */
    public static function integer(string $text): ?int
    {
        $digits = $text[0] === '-' ? substr($text, 1) : $text;

        return $digits !== '' && ctype_digit($digits) ? (int) $text : null;
    }

    /** @internal for TypeParser */
    public static function looksLikeFloat(string $text): bool
    {
        return preg_match('/^-?\d+\.\d+$/', $text) === 1;
    }
}
