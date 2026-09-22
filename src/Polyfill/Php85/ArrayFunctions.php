<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * PHP 8.5's array_first/array_last, backfilled so the floor can stay
 * at 8.3 (D-001) while call sites use the newer idiom. Same
 * convention as src/Polyfill/Php84/ArrayFunctions.php: guarded by
 * function_exists, global namespace, each public function delegates
 * to an always-defined _crucible_-prefixed internal so the logic
 * stays unit-testable regardless of which PHP version runs the
 * suite.
 *
 * PHPStan's own bundled stubs for these are even plainer than
 * array_any's: bare `array $array`, no callback, `mixed` out. Only
 * `@param array<array-key, mixed> $array` is added here, to satisfy
 * this project's own level-max iterable-value-type rule — the native
 * stubs skip that too, but external stubs aren't held to it.
 *
 * Delete this whole Php85/ folder, its composer.json autoload.files
 * entry, and tests/unit/Polyfill/Php85/ the day the floor rises to
 * 8.5 again.
 */

if (!\function_exists('array_first')) {
    /** @param array<array-key, mixed> $array */
    function array_first(array $array): mixed
    {
        return \_crucible_array_first($array);
    }
}

/** @param array<array-key, mixed> $array */
function _crucible_array_first(array $array): mixed
{
    foreach ($array as $value) {
        return $value;
    }

    return null;
}

if (!\function_exists('array_last')) {
    /** @param array<array-key, mixed> $array */
    function array_last(array $array): mixed
    {
        return \_crucible_array_last($array);
    }
}

/** @param array<array-key, mixed> $array */
function _crucible_array_last(array $array): mixed
{
    if ($array === []) {
        return null;
    }

    return $array[\array_key_last($array)];
}
