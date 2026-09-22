<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Self-hosted proof for pest()-level configuration and shared
 * datasets (G2 slice 4). This file declares no uses(): its binding
 * class, trait, and group arrive from tests/unit/Pest.php via
 * pest()->extend()->use()->group()->in().
 */

use LucianoPereira\Crucible\Tests\Dialect\HigherOrderCase;

\beforeEach(function (): void {
    // The suite-level hook ran first (spec §3) and assigned the
    // declared state — the concatenation is the ordering proof.
    $this->hookOrder = $this->fromSuiteConfig . ' then file';
});

\test('the scoped extend() provides the binding class', function (): void {
    \expect($this)->toBeInstanceOf(HigherOrderCase::class);
});

\test('the scoped use() mixes the trait in', function (): void {
    \expect($this->brew('arabica'))->toBe('brewed arabica');
});

\test('global hooks run before file hooks', function (): void {
    \expect($this->hookOrder)->toBe('loaded then file');
});

\test('a named dataset from Pest.php resolves', function (string $bean): void {
    \expect($bean)->toBeIn(['arabica', 'robusta']);
})->with('beans');

\test('a dataset from a Datasets file resolves with its keys', function (string $brew): void {
    \expect($brew)->toBeIn(['espresso', 'filter']);
})->with('brews');

\test('a lazy dataset closure materializes at build time', function (int $number, int $square): void {
    \expect($number * $number)->toBe($square);
})->with('lazy squares');

\test('bound rows resolve after beforeEach, bound to the instance', function (ArrayObject $bag): void {
    \expect($bag[0])->toBe('loaded');
})->with([fn(): ArrayObject => new ArrayObject([$this->fromSuiteConfig])]);

\test('associative rows bind to parameters by name', function (string $right, string $left): void {
    \expect($left)->toBe('L');
    \expect($right)->toBe('R');
})->with([['left' => 'L', 'right' => 'R']]);

\test('named and inline factors multiply', function (string $bean, int $shots): void {
    \expect($bean)->toBeIn(['arabica', 'robusta']);
    \expect($shots)->toBeIn([1, 2]);
})->with('beans')->with([1, 2]);

// Regression for the dataset key-collision bug (Datasets/Brews.php's
// own comment has the full story) — proves every row actually runs,
// which PestScopesTest's direct materialize() test alone can't:
// that test proves the array has the right shape, this one proves
// the engine actually iterates all of it end to end, self-hosted.
\test('a dataset composed of multiple yield from sub-generators runs every row', function (string $method, int $shots): void {
    \expect($method)->toBeIn(['espresso', 'filter']);
    \expect($shots)->toBeGreaterThan(0);
})->with('composed shots');
