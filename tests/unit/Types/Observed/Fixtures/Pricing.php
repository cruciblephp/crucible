<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Types\Observed\Fixtures;

interface Pricing
{
    /** @return positive-int */
    public function discount(int $percent): float|int;
}
