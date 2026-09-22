<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

// Mirrors the real spatie/laravel-data shape that first exposed the
// bug: a named fixture class scoped to the closure body, not the file.
// It must never be mistaken for a top-level PHPUnit TestCase candidate.

\it('builds a fixture class scoped to the closure', function (): void {
    class NestedClassPestFixture
    {
        public function value(): int
        {
            return 42;
        }
    }

    \expect((new NestedClassPestFixture())->value())->toBe(42);
});
