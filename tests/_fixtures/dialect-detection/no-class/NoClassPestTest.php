<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

// A real Pest test file named *Test.php with no class at all — the
// naming convention every real Pest project actually uses.

\it('has no class at all', function (): void {
    \expect(1 + 1)->toBe(2);
});

\it('has a second test', function (): void {
    \expect('a')->toBe('a');
});
