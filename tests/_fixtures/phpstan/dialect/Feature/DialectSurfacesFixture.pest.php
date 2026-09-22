<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Analyzed by the black-box extension test (D-049/D-050/D-060), never
 * executed. Three surfaces the other fixtures do not reach: the
 * method-level closure binding, the magic chain grammar, and the
 * Mockery mock surface.
 */

use LucianoPereira\Crucible\Double\Mockery\MockeryMock;

// D-050 method-level, ScopeRegistration: the per-test hooks bind to
// the scoped class, exactly as the function-level surface does.
\pest()->beforeEach(function (): void {
    \PHPStan\dumpType($this);
});

\pest()->afterEach(function (): void {
    \PHPStan\dumpType($this->ristretto());
});

// D-050 method-level, TestCall: the real skip(Closure $condition)
// surface, evaluated after beforeEach and bound the same way.
\test('the skip closure binds', function (): void {})
    ->skip(function (): bool {
        \PHPStan\dumpType($this);

        return false;
    });

// D-050 method-level, TestCall: a magic chain step's 'arguments'
// parameter — the higher-order form, collected via __call.
\test('the higher-order step binds', function (): void {})
    ->expect(function (): string {
        \PHPStan\dumpType($this);

        return 'ristretto';
    });

// The magic reflection extension: members Expectation never declares.
// __get descends, __call continues the chain — both stay Expectation,
// so an undeclared member is a chain step, not an unknown method.
\test('the magic chain continues in kind', function (): void {
    \PHPStan\dumpType(\expect(['a', 'b'])->each);
    \PHPStan\dumpType(\expect('a')->toBeUppercaseSomeday());

    // TestCall's own __get grammar: a property step, in kind.
    \PHPStan\dumpType(\test('nested', function (): void {})->group);
});

// D-060: a MockeryMock accepts any method; the four verbs open an
// expectation chain, everything else is the runtime's mixed. Declared
// rather than built — the extension keys off the type being known,
// which is what an annotated or typed mock gives it in real code.
$mockerySurface = static function (MockeryMock $mock): void {
    \PHPStan\dumpType($mock->shouldReceive('brew'));
    \PHPStan\dumpType($mock->allows('brew'));
    \PHPStan\dumpType($mock->neverDeclaredAnywhere());
};
