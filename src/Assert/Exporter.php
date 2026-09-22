<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

use UnitEnum;

use function addcslashes;
use function get_debug_type;
use function get_mangled_object_vars;
use function get_resource_id;
use function get_resource_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_resource;
use function is_string;
use function spl_object_id;
use function sprintf;
use function str_repeat;
use function str_replace;
use function var_export;

/**
 * The canonical serialization of PHP values for failure output.
 * One serializer drives comparison diffs (and, later, snapshots), so
 * what you compare is exactly what you see — the jest-diff /
 * pretty-format lesson.
 *
 * Failure *presentation* is Crucible-designed; only pass/fail semantics
 * and the assertion API are bound to the PHPUnit spec (DESIGN.md).
 */
final class Exporter
{
    private const int MAX_DEPTH = 16;

    public static function export(mixed $value): string
    {
        return self::exportValue($value, 0, []);
    }

    /**
     * A single-line, shortened description for failure sentences.
     */
    public static function describe(mixed $value): string
    {
        if (is_array($value)) {
            return 'an array';
        }

        if ($value instanceof UnitEnum) {
            return self::exportEnum($value);
        }

        if (is_object($value)) {
            return sprintf('an instance of %s', $value::class);
        }

        return self::export($value);
    }

    /**
     * @param list<int> $objectStack spl_object_ids of objects being exported
     */
    private static function exportValue(mixed $value, int $depth, array $objectStack): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        if (is_string($value)) {
            return "'" . addcslashes(str_replace('\\', '\\\\', $value), "'\0..\10\13\14\16..\37") . "'";
        }

        if (is_array($value)) {
            return self::exportArray($value, $depth, $objectStack);
        }

        if ($value instanceof UnitEnum) {
            return self::exportEnum($value);
        }

        if (is_object($value)) {
            return self::exportObject($value, $depth, $objectStack);
        }

        if (is_resource($value)) {
            return sprintf('resource(%d) of type (%s)', get_resource_id($value), get_resource_type($value));
        }

        // Closed resources and anything the branches above cannot see.
        return get_debug_type($value);
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<int>               $objectStack
     */
    private static function exportArray(array $value, int $depth, array $objectStack): string
    {
        if ($value === []) {
            return '[]';
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[...]';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines  = [];

        foreach ($value as $key => $item) {
            $lines[] = sprintf(
                '%s%s => %s,',
                $indent,
                is_string($key) ? "'" . $key . "'" : (string) $key,
                self::exportValue($item, $depth + 1, $objectStack),
            );
        }

        return "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . ']';
    }

    /**
     * @param list<int> $objectStack
     */
    private static function exportObject(object $value, int $depth, array $objectStack): string
    {
        $id = spl_object_id($value);

        foreach ($objectStack as $seen) {
            if ($seen === $id) {
                return $value::class . ' {*RECURSION*}';
            }
        }

        if ($depth >= self::MAX_DEPTH) {
            return $value::class . ' {...}';
        }

        $objectStack[] = $id;
        $properties    = get_mangled_object_vars($value);

        if ($properties === []) {
            return $value::class . ' {}';
        }

        $indent = str_repeat('    ', $depth + 1);
        $lines  = [];

        foreach ($properties as $name => $item) {
            // Mangled keys of private/protected properties contain "\0Class\0name".
            $plain   = str_replace("\0", '::', (string) $name);
            $lines[] = sprintf('%s%s: %s,', $indent, $plain, self::exportValue($item, $depth + 1, $objectStack));
        }

        return $value::class . " {\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $depth) . '}';
    }

    private static function exportEnum(UnitEnum $value): string
    {
        return $value::class . '::' . $value->name;
    }
}
