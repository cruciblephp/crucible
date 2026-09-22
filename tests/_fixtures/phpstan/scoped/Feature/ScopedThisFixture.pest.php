<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * The D-067 pin: this file declares no uses() — $this arrives from
 * the ancestor Pest.php's pest()->extend(ScopedCase::class)
 * ->in('Feature') registration, resolved at analysis time.
 */

\test('the scoped class binds', function (): void {
    \PHPStan\dumpType($this);
    \expect($this->espresso())->toBe('espresso');
});
