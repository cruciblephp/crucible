<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Dialect;

use LucianoPereira\Crucible\Framework\TestCase;

/** The class this fixture tree's Pest.php scopes onto Feature/. */
class DialectCase extends TestCase
{
    public function ristretto(): string
    {
        return 'ristretto';
    }
}
