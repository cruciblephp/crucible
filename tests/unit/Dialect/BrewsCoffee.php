<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect;

/**
 * Mixed into PestDialectConfig.pest.php's binding class via
 * pest()->use() in tests/unit/Pest.php — proves trait composition.
 */
trait BrewsCoffee
{
    public function brew(string $bean): string
    {
        return 'brewed ' . $bean;
    }
}
