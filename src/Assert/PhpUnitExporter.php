<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert;

use BackedEnum;
use Throwable;
use UnitEnum;

use function bin2hex;
use function get_resource_id;
use function get_resource_type;
use function gettype;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_numeric;
use function is_object;
use function is_resource;
use function is_string;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function str_repeat;
use function strpos;
use function strtr;
use function substr;
use function var_export;

/**
 * A value as PHPUnit 13.4.3 writes it inside a failure sentence, measured
 * value by value: `Array &0 [`, `stdClass Object #12 (`, a line break as
 * `\n` followed by the break itself. Only failure sentences use it; the
 * comparison diff and every other output keep {@see Exporter}.
 */
final class PhpUnitExporter
{
    private int $arrays = 0;

    /** @var array<int, true> objects being exported, by id */
    private array $objects = [];

    public static function export(mixed $value): string
    {
        return (new self())->value($value, 0);
    }

    /** The subject of PHPUnit's default sentence: `an array`, `an instance of class X`, any other value exported. */
    public static function subject(mixed $value): string
    {
        return match (true) {
            is_array($value)  => 'an array',
            is_object($value) => 'an instance of class ' . $value::class,
            default           => self::export($value),
        };
    }

    /** `an array`, `an integer`, `a string`: the noun some sentences use in place of the value. */
    public static function noun(mixed $value): string
    {
        return match (true) {
            is_array($value)  => 'an array',
            is_int($value)    => 'an integer',
            is_float($value)  => 'a float',
            is_bool($value)   => 'a boolean',
            is_string($value) => $value === '' ? 'an empty string' : 'a string',
            $value === null   => 'null',
            is_object($value) => 'an instance of class ' . $value::class,
            default           => 'a ' . gettype($value),
        };
    }

    private function value(mixed $value, int $depth): string
    {
        return match (true) {
            $value === null            => 'null',
            is_bool($value)            => $value ? 'true' : 'false',
            is_int($value)             => (string) $value,
            is_float($value)           => $this->float($value),
            is_string($value)          => $this->string($value),
            is_array($value)           => $this->array($value, $depth),
            $value instanceof UnitEnum => sprintf('%s Enum #%d (%s%s)', $value::class, spl_object_id($value), $value->name, $value instanceof BackedEnum ? ', ' . $this->value($value->value, $depth) : ''),
            is_object($value)          => $this->object($value, $depth),
            is_resource($value)        => sprintf('resource(%d) of type (%s)', get_resource_id($value), get_resource_type($value)),
            default                    => 'resource (closed)',
        };
    }

    private function float(float $value): string
    {
        if (is_nan($value)) {
            return 'NAN';
        }

        if (is_infinite($value)) {
            return $value > 0 ? 'INF' : '-INF';
        }

        return var_export($value, true);
    }

    private function string(string $value): string
    {
        if (str_contains($value, "\0")) {
            return 'Binary String: 0x' . bin2hex($value);
        }

        return "'" . strtr($value, ["\r\n" => "\\r\\n\n", "\n" => "\\n\n", "\r" => "\\r\n"]) . "'";
    }

    /** @param array<array-key, mixed> $value */
    private function array(array $value, int $depth): string
    {
        $number = $this->arrays++;

        if ($value === []) {
            return sprintf('Array &%d []', $number);
        }

        return sprintf('Array &%d [', $number) . "\n" . $this->entries($value, $depth) . str_repeat('    ', $depth) . ']';
    }

    private function object(object $value, int $depth): string
    {
        $id = spl_object_id($value);

        if (isset($this->objects[$id])) {
            return sprintf('%s Object #%d', $value::class, $id);
        }

        $this->objects[$id] = true;
        $properties         = [];

        foreach ((array) $value as $name => $property) {
            $name = (string) $name;

            // A mangled name is "\0Class\0name" or "\0*\0name"; PHPUnit shows the name.
            if (str_contains($name, "\0")) {
                $name = substr($name, (int) strpos($name, "\0", 1) + 1);
            }

            if ($value instanceof Throwable && $name === 'trace') {
                continue;
            }

            $properties[is_numeric($name) ? (int) $name : $name] = $property;
        }

        $text = $properties === []
            ? sprintf('%s Object #%d ()', $value::class, $id)
            : sprintf('%s Object #%d (', $value::class, $id) . "\n" . $this->entries($properties, $depth) . str_repeat('    ', $depth) . ')';

        unset($this->objects[$id]);

        return $text;
    }

    /** @param array<array-key, mixed> $entries */
    private function entries(array $entries, int $depth): string
    {
        $lines  = [];
        $indent = str_repeat('    ', $depth + 1);

        foreach ($entries as $key => $entry) {
            $lines[] = sprintf('%s%s => %s,', $indent, is_int($key) ? (string) $key : "'" . $key . "'", $this->value($entry, $depth + 1));
        }

        return implode("\n", $lines) . "\n";
    }
}
