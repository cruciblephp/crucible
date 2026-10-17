<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 *
 * D-144. Each dump's expected type is what pest-plugin-phpstan 5.2.2
 * answered for the same lines.
 */

\beforeEach(function (): void {
    $this->user  = 'grace';
    $this->items = ['a', 'b'];
});

\describe('nested', function (): void {
    \beforeEach(function (): void {
        $this->inner = true;
    });

    \test('a hook property keeps the type it was given', function (): void {
        \PHPStan\dumpType($this->user);
        \PHPStan\dumpType($this->items);
        \PHPStan\dumpType($this->inner);
    });
});

\test('nothing set it, so it is mixed and no error', function (): void {
    \PHPStan\dumpType($this->nobodySetsThis);
});

\test('an assignment in the test is the scope own', function (): void {
    $this->local = 5;
    \PHPStan\dumpType($this->local);
});

\test('a declared property is the class own', function (): void {
    \PHPStan\dumpType($this->declared);
    \PHPStan\dumpType($this);
});
