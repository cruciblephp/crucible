<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Self-hosted proof for the test-definition long tail (G2 slice 3):
 * todo/wip/done, higher-order tests, skip variants, fails(),
 * repeat(), describe chainables, Cartesian datasets. Runs with
 * uses(), so it also exercises binding onto a custom test case.
 */

use LucianoPereira\Crucible\Tests\Dialect\HigherOrderCase;

\uses(HigherOrderCase::class);

$repeatLog = new ArrayObject();

\beforeEach(function (): void {
    $this->brew = 'espresso';
});

// ── Todos ────────────────────────────────────────────────────────

// A todo (bare, or ->todo()) is a placeholder: it blocks and reports
// incomplete, which ->expectsIncomplete() asserts green instead of
// swelling the tally (D-073). wip and done run their bodies like Pest
// (D-074) — the wip below runs and passes.

\test('a bare description is a todo')->expectsIncomplete();

\it('will get a body eventually')->todo(assignee: 'luciano', issue: 31, note: 'after G2')->expectsIncomplete('issue #31');

\test('work in progress runs its body like Pest', function (): void {
    \expect($this->brew)->toBe('espresso');
})->wip();

// A body-less wip has nothing to run, so it folds to a plain todo and
// blocks — pending, not a phantom pass (D-074).
\test('a body-less wip folds to a todo')->wip()->expectsIncomplete();

\test('a done todo runs its body', function (): void {
    \expect($this->brew)->toBe('espresso');
})->done(note: 'landed in slice 3');

// ── Higher-order tests ───────────────────────────────────────────

\it('chains methods on the test case')->visit('/home')->visit('/about')->assertVisited('/about');

\test('lazy expectation chains')->expect(fn(): int => $this->double(21))->toBe(42)->toBeInt();

\test('higher-order expect receives dataset arguments')
    ->with([[2, 4], [5, 10]])
    ->expect(fn(int $in, int $out): bool => $this->double($in) === $out)
    ->toBeTrue();

// ── Skips ────────────────────────────────────────────────────────

\test('closure skips are decided after beforeEach', function (): void {
    \expect($this->brew)->toBe('espresso');
})->skip(fn(): bool => $this->brew !== 'espresso', 'hooks must run first');

\test('skipOnPhp with an operator constraint', function (): void {
    \expect(PHP_VERSION)->toBeString();
})->skipOnPhp('<8.5.0');

\test('skipOnWindows leaves other platforms running', function (): void {
    \expect(PHP_OS_FAMILY)->not->toBe('Windows');
})->skipOnWindows();

// ── Expected failure, exceptions ─────────────────────────────────

\test('fails() inverts the outcome', function (): void {
    \expect(1)->toBe(2);
})->fails('is identical to');

\test('throws() accepts a message fragment', function (): void {
    throw new LogicException('the grinder is jammed');
})->throws('grinder is jammed');

\test('throwsUnless() with a truthy condition expects nothing', function (): void {
    \expect(true)->toBeTrue();
})->throwsUnless(true, RuntimeException::class);

// ── Repetition and Cartesian datasets ────────────────────────────

\test('repeat() runs the body once per repetition', function () use ($repeatLog): void {
    $repeatLog->append('run');
    \expect($repeatLog->count())->toBeLessThanOrEqual(3);
})->repeat(3);

\test('multiple with() calls form a Cartesian product', function (int $number, string $letter): void {
    \expect($number)->toBeIn([1, 2]);
    \expect($letter)->toBeIn(['x', 'y']);
})->with([1, 2])->with(['x', 'y']);

// ── describe() chainables ────────────────────────────────────────

\describe('a configured block', function (): void {
    \test('inherits the block group and dataset', function (string $bean): void {
        \expect($bean)->toBeIn(['arabica', 'robusta']);
    });

    \it('applies to every contained test', function (string $bean): void {
        \expect($bean)->toBeString();
    });
})->group('pest-described')->with(['arabica', 'robusta']);

// ── Higher-order expectations ────────────────────────────────────

// A property chain re-roots after each terminal matcher — every
// ->prop reads from the ORIGINAL subject, not the previous leaf
// (real Pest's HigherOrderExpectation::$shouldReset). Verified
// against a real spatie/laravel-data shape:
// ->isOptional->toBeFalse()->isNullable->toBeTrue() reads both
// properties off the same object, not $isOptional's boolean value.
\test('a property chain re-roots to the original subject after each matcher', function (): void {
    $subject = new class {
        public bool $isOptional = false;
        public bool $isNullable = true;
    };

    \expect($subject)
        ->isOptional->toBeFalse()
        ->isNullable->toBeTrue();
});

\test('->not() works as a method call, not only as a property', function (): void {
    \expect(['a' => 1])->not()->toHaveKey('b');
});

\test('a missing property or key is null, not a failure', function (): void {
    \expect(new stdClass())->missing->toBeNull();
    \expect([])->missing->toBeNull();
});

\test('an uninitialized typed property is null, not a fatal', function (): void {
    $subject = new class {
        public string $uninitialized;
    };

    \expect($subject)->uninitialized->toBeNull();
});

// A matcher that loops calling assert() per item (toContain(),
// toHaveKeys(), toHaveProperties()) only ever saw the negation flag
// on its first iteration — every later item silently ran un-negated.
// Found via a real spatie/laravel-data test negating an 11-item list;
// verified against real Pest's own source that toContain()/
// toHaveProperties() (no OppositeExpectation override) get a WEAK
// negation — fails only when every value is present — while
// toHaveKeys() (which does have an override there) gets the
// STRONGER one: fails as soon as any single key is found.
\test('not()->toContain() with multiple needles fails only when all are present', function (): void {
    \expect(['a', 'b'])->not()->toContain('a', 'z'); // z absent: passes
    \expect(['a', 'b'])->not()->toContain('z', 'y'); // both absent: passes
});

\test('not()->toContain() with multiple needles, all present', function (): void {
    \expect(['a', 'b'])->not()->toContain('a', 'b');
})->fails('contains all of');

\test('not()->toHaveKeys() fails as soon as any single key is found, even last in the list', function (): void {
    \expect(['a' => 1, 'b' => 2])->not()->toHaveKeys(['y', 'a']);
})->fails("not to have the key 'a'");

\test('not()->toHaveProperties() fails only when every property is present', function (): void {
    $subject = new class {
        public int $p1 = 1;
    };

    \expect($subject)->not()->toHaveProperties(['p1', 'p2']); // p2 absent: passes
});
