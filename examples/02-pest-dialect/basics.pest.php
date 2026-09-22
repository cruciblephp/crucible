<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The Pest dialect — the expressive one.
 *
 * `*.pest.php` files run natively, mixed freely with PHPUnit-dialect
 * classes in the same suite and the same run. No engine mode to switch,
 * no separate binary.
 */

test('a test is a description and a closure', function (): void {
    expect(2 + 2)->toBe(4);
});

// `it` reads better for behaviour, and prefixes the reported name with
// "it", so `it('rounds up')` reports as "it rounds up".
it('rounds up', function (): void {
    expect((int) ceil(1.2))->toBe(2);
});

describe('expectations', function (): void {
    it('chains', function (): void {
        expect('crucible')
            ->toBeString()
            ->toHaveLength(8)
            ->toStartWith('cru');
    });

    it('negates with not', function (): void {
        expect([1, 2, 3])
            ->toBeArray()
            ->not->toBeEmpty()
            ->toContain(2);
    });

    it('reaches into values with higher-order expectations', function (): void {
        $user = ['name' => 'Ada', 'roles' => ['admin', 'author']];

        // Naming a key narrows the expectation TO that value, so the
        // rest of the chain is about the name, not about $user. To ask
        // about another key, start again with ->and().
        expect($user)
            ->name->toBe('Ada')
            ->and($user['roles'])->toHaveCount(2)->toContain('admin');
    });
});

describe('hooks', function (): void {
    // beforeEach runs before each test in this describe block; the outer
    // scope's hooks (and any in a Pest.php above this file) run first.
    $counter = new class {
        public int $value = 0;
    };

    beforeEach(function () use ($counter): void {
        $counter->value = 10;
    });

    it('sees the value the hook set', function () use ($counter): void {
        expect($counter->value)->toBe(10);
    });

    it('sees it again, freshly', function () use ($counter): void {
        $counter->value++;

        expect($counter->value)->toBe(11);
    });
});

// Datasets: one test per row, each row named in the output. Pass a list
// of argument-lists, or name them for readable failures.
it('uppercases', function (string $input, string $expected): void {
    expect(\strtoupper($input))->toBe($expected);
})->with([
    'lowercase' => ['crucible', 'CRUCIBLE'],
    'mixed'     => ['CrUcIbLe', 'CRUCIBLE'],
    'already'   => ['CRUCIBLE', 'CRUCIBLE'],
]);

// Two datasets multiply — this runs 4 times, the Cartesian product.
it('adds any two positives', function (int $a, int $b): void {
    expect($a + $b)->toBeGreaterThan(0);
})->with([1, 2])->with([10, 20]);

// A test that is expected to fail, asserted as such. Useful for pinning
// a known-bad case without leaving the suite red.
it('fails on purpose', function (): void {
    expect(true)->toBeFalse();
})->fails();

// Groups work here too, and are what `--group` and impact rules select.
it('belongs to a group', function (): void {
    $evens = \array_filter([1, 2, 3, 4], static fn(int $n): bool => $n % 2 === 0);

    expect(\array_sum($evens))->toBe(6)
        ->and(\count($evens))->toBe(2);
})->group('arithmetic');
