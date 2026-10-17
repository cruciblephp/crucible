<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan\Hooks;

use LucianoPereira\Crucible\Framework\TestCase;

/** The class the fixture tree's Pest.php binds; it declares one property. */
class HookCase extends TestCase
{
    public string $declared = 'declared';
}
