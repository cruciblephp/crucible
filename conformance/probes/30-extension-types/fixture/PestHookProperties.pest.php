<?php

declare(strict_types=1);

namespace CrucibleProbe\Types;

use function PHPStan\dumpType;

/*
 * What a beforeEach puts on $this, as the analyser sees it in the file's
 * tests (D-144). Labels as in PestNarrowing.
 */

beforeEach(function (): void {
    $this->user  = 'grace';
    $this->items = ['a', 'b'];
});

describe('nested', function (): void {
    beforeEach(function (): void {
        $this->inner = true;
    });

    test('hook properties', function (): void {
        dumpType($this->user); // pest.hook.string
        dumpType($this->items); // pest.hook.array
        dumpType($this->inner); // pest.hook.nested
    });
});

test('a property nothing sets', function (): void {
    dumpType($this->nobodySetsThis); // pest.hook.unset
});

test('an assignment inside the test', function (): void {
    $this->local = 5;
    dumpType($this->local); // pest.hook.local
});
