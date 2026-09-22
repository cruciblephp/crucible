<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\Large;
use LucianoPereira\Crucible\Attributes\Medium;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Runner\TimeLimit;

#[CoversClass(TimeLimit::class)]
final class TimeLimitTest extends TestCase
{
    public function testEachDeclaredSizeCarriesTheSpecsBudget(): void
    {
        $this->assertSame(1, TimeLimit::forTest(MetadataCollection::from(new Small()), null));
        $this->assertSame(10, TimeLimit::forTest(MetadataCollection::from(new Medium()), null));
        $this->assertSame(60, TimeLimit::forTest(MetadataCollection::from(new Large()), null));
    }

    public function testADeclaredSizeWinsOverTheDefault(): void
    {
        $this->assertSame(1, TimeLimit::forTest(MetadataCollection::from(new Small()), 45));
    }

    public function testWithoutASizeTheDefaultDecidesAndNothingIsNotZero(): void
    {
        $this->assertSame(45, TimeLimit::forTest(MetadataCollection::from(), 45));

        // No size and no default means unbounded — never a zero budget
        // that would make every test late.
        $this->assertNull(TimeLimit::forTest(MetadataCollection::from(), null));
        $this->assertNull(TimeLimit::forTest(MetadataCollection::from(), 0));
    }
}
