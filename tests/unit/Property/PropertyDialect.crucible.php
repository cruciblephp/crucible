<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * The crucible dialect's property() surface (G5) proving itself: the
 * same engine runner the phpunit dialect calls directly, one
 * function call in this dialect.
 */

use LucianoPereira\Crucible\Property\Gen;

\property('string concatenation is associative', Gen::string(8), Gen::string(8), Gen::string(8), function (string $a, string $b, string $c): void {
    \expect(($a . $b) . $c)->toBe($a . ($b . $c));
});

\property('sorting is idempotent', Gen::listOf(Gen::int(-100, 100)), function (array $items): void {
    \sort($items);
    $once = $items;
    \sort($items);

    \expect($items)->toBe($once);
});

\property('json round-trips lists of ints', Gen::listOf(Gen::int()), function (array $items): void {
    \expect(\json_decode(\json_encode($items, JSON_THROW_ON_ERROR), true))->toBe($items);
})->group('property');
