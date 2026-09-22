<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Metadata;

use Deprecated;
use LucianoPereira\Crucible\Attributes\Before;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Attributes\Depends;
use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Attributes\Test;
use LucianoPereira\Crucible\Attributes\TestDox;
use LucianoPereira\Crucible\Attributes\TestWith;
use LucianoPereira\Crucible\Metadata\MetadataParser;

#[Group('child')]
#[Small]
#[CoversClass(MetadataParser::class)]
final class FixtureChild extends FixtureParent
{
    #[Test]
    #[Group('fast')]
    #[DataProvider('provideSums')]
    #[TestWith([1, 2, 3])]
    #[TestWith([0, 0, 0], 'named row')]
    #[Before(5)]
    #[TestDox('renders sums')]
    public function sums(): void {}

    #[Deprecated('a foreign attribute the parser must ignore')]
    #[Depends('sums')]
    public function decorated(): void {}
}
