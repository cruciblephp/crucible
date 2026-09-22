<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Self-hosted proof for the crucible dialect v1 (G2b, D-034): the whole
 * pest vocabulary works in *.crucible.php files, and the two extras —
 * check() and table() — remove names, nesting, and dataset plumbing.
 */

// ── check(): nameless tests, expectation chained on the handle ──

\check(fn(): int => 1 + 2)->toBe(3);

\check(fn(): ?string => null)->toBeNull()->group('crucible-dialect');

\check(fn() => throw new RuntimeException('boom'))->toThrow(RuntimeException::class, 'boom');

\check(fn(): array => [1, 2, 3])->toBeList()->toHaveCount(3)->toContain(2)->not->toContain(9);

\check(fn(): string => \strtoupper('crucible'), 'an explicit description wins')->toBe('CRUCIBLE');

\check(fn(): int => 2 * 21)->toBe(42); // a trailing comment is the name

\check(fn(): string => 'https://crucible.dev')->toBeUrl(); // slashes in strings cannot fool the tokenizer

// Two checks with identical source lines stay uniquely named.
\check(fn(): bool => true)->toBeTrue();
\check(fn(): bool => true)->toBeTrue();

// The suite-level Pest.php hook binds here too ($this is live).
\check(fn(): mixed => $this->fromSuiteConfig)->toBe('loaded');

// ── table(): I/O rows, last element is the expected value ──

\table(\str_repeat(...), [
    ['ab', 2, 'abab'],
    ['x', 3, 'xxx'],
]);

\table(\strtoupper(...), [
    'uppercases'       => ['crucible', 'CRUCIBLE'],
    'leaves digits be' => ['v1', 'V1'],
]);

\table(\array_sum(...), [
    [[1, 2, 3], 6],
    [[], 0],
]);

// ── the pest vocabulary is fully available in .crucible.php ──

\beforeEach(function (): void {
    $this->dialect = 'crucible';
});

\test('pest-style tests coexist', function (): void {
    \expect($this->dialect)->toBe('crucible');
});

\describe('described blocks too', function (): void {
    \it('nests as usual', function (): void {
        \expect('crucible')->toEndWith('e');
    });

    \check(fn() => $this->dialect)->toBe('crucible');
});
