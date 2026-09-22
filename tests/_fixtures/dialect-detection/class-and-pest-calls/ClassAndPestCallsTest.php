<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

// Mirrors the real spatie/laravel-data shape that proved the
// "class + Pest calls = ambiguous" assumption wrong: a top-level
// helper class (there, a PHP #[Attribute] class) declared right next
// to real it() calls, in InjectPropertyValuesTest.php. Pest calls
// win; the class is just along for the ride.

class ClassAndPestCallsFixtureAttribute
{
    public function value(): int
    {
        return 7;
    }
}

\it('runs even though a top-level class is declared in the same file', function (): void {
    \expect((new ClassAndPestCallsFixtureAttribute())->value())->toBe(7);
});
