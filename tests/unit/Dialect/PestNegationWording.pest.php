<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

/*
 * A negation whose positive form held, in Pest 5.3.3's own words: the
 * value shortened, the matcher's name in words, each argument exported.
 * Every expected text is that version's for the same call.
 */

\test('a negated matcher without arguments', function (): void {
    \expect('abc')->not->toBeString();
})->fails("Expecting 'abc' not to be string .");

\test('a negated matcher with arguments', function (): void {
    \expect(2)->not->toBeBetween(1, 3);
})->fails('Expecting 2 not to be between 1 3.');

\test('an array is shortened, its argument is not', function (): void {
    \expect([1, 2])->not->toBe([1, 2]);
})->fails("Expecting […] not to be Array &0 [\n    0 => 1,\n    1 => 2,\n].");

\test('an object with properties is its class', function (): void {
    \expect((object) ['a' => 1])->not->toHaveProperty('a');
})->fails("Expecting stdClass not to have property 'a'.");

\test('a long string is cut and loses its line breaks', function (): void {
    \expect(\str_repeat('a', 60))->not->toBeString();
})->fails("Expecting 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaa…aaaaaa' not to be string .");

\test('a key with another value is not having it', function (): void {
    \expect(['a' => 1])->not->toHaveKey('a', 2);
});

\test('an unknown method on a value that is no object', function (): void {
    \expect(1)->toBeOneOf([1]);
})->throws(\BadMethodCallException::class, 'Method [toBeOneOf] does not exist in [integer].');

\test('the bounds are checked as PHPUnit checks them', function (): void {
    \expect(5)->toBeBetween(1, 3);
})->fails('Failed asserting that 5 is equal to 3 or is less than 3.');

\test('a string length is compared as equal', function (): void {
    \expect('abc')->toHaveLength(2);
})->fails('Failed asserting that 3 matches expected 2.');
