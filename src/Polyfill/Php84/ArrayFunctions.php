<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * PHP 8.4's array_any/array_all/array_find/array_find_key, backfilled
 * so the floor can sit at 8.3 (D-001). Same convention as
 * src/Dialect/Pest/functions.php: guarded by function_exists, global
 * namespace, one function per guard. Each public function delegates
 * to an always-defined _crucible_-prefixed internal so the real logic
 * stays unit-testable regardless of which PHP version runs the suite
 * — on 8.4+ the guard skips entirely and the native function wins,
 * but the internal (and its test coverage) exist unconditionally.
 *
 * The callback stays a bare `callable`, deliberately unshaped, to
 * match PHPStan's own bundled stubs for these functions exactly — the
 * native stubs don't shape it either, so matching that avoids this
 * polyfill being stricter than what callers actually face on 8.4+.
 * The `$array` param does carry `@param array<array-key, mixed>`,
 * which the native stubs skip (external stubs aren't held to this
 * project's own level-max iterable-value-type requirement, but code
 * living in src/ is).
 *
 * Delete this whole Php84/ folder, its composer.json autoload.files
 * entry, and tests/unit/Polyfill/Php84/ the day the floor rises to
 * 8.4 again.
 */

if (!\function_exists('array_any')) {
    /** @param array<array-key, mixed> $array */
    function array_any(array $array, callable $callback): bool
    {
        return \_crucible_array_any($array, $callback);
    }
}

/** @param array<array-key, mixed> $array */
function _crucible_array_any(array $array, callable $callback): bool
{
    foreach ($array as $key => $value) {
        if ($callback($value, $key)) {
            return true;
        }
    }

    return false;
}

if (!\function_exists('array_all')) {
    /** @param array<array-key, mixed> $array */
    function array_all(array $array, callable $callback): bool
    {
        return \_crucible_array_all($array, $callback);
    }
}

/** @param array<array-key, mixed> $array */
function _crucible_array_all(array $array, callable $callback): bool
{
    foreach ($array as $key => $value) {
        if (!$callback($value, $key)) {
            return false;
        }
    }

    return true;
}

if (!\function_exists('array_find')) {
    /** @param array<array-key, mixed> $array */
    function array_find(array $array, callable $callback): mixed
    {
        return \_crucible_array_find($array, $callback);
    }
}

/** @param array<array-key, mixed> $array */
function _crucible_array_find(array $array, callable $callback): mixed
{
    foreach ($array as $key => $value) {
        if ($callback($value, $key)) {
            return $value;
        }
    }

    return null;
}

if (!\function_exists('array_find_key')) {
    /** @param array<array-key, mixed> $array */
    function array_find_key(array $array, callable $callback): mixed
    {
        return \_crucible_array_find_key($array, $callback);
    }
}

/** @param array<array-key, mixed> $array */
function _crucible_array_find_key(array $array, callable $callback): mixed
{
    foreach ($array as $key => $value) {
        if ($callback($value, $key)) {
            return $key;
        }
    }

    return null;
}
