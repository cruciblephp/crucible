<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Mutation\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_ALL)]
final readonly class Marker
{
    public function __construct(public int $weight) {}
}
