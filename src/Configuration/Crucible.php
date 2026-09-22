<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

/**
 * The entry point used inside a project's crucible.php file:
 *
 *     use LucianoPereira\Crucible\Configuration\Crucible;
 *
 *     return Crucible::configure()
 *         ->bootstrap('vendor/autoload.php')
 *         ->testSuite('unit', 'tests/Unit')
 *         ->source(include: ['src'])
 *         ->strict();
 */
final class Crucible
{
    public static function configure(): Builder
    {
        return new Builder();
    }
}
