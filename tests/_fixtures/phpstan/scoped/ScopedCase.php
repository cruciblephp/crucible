<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Scoped;

use LucianoPereira\Crucible\Framework\TestCase;

/** The class the fixture tree's Pest.php scopes onto Feature/. */
class ScopedCase extends TestCase
{
    public function espresso(): string
    {
        return 'espresso';
    }
}
