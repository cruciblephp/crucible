<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Test;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Test\TestId;

#[CoversClass(TestId::class)]
final class TestIdTest extends TestCase
{
    public function testStringFormIsFileNameAndOptionalDataset(): void
    {
        $plain       = new TestId('tests/Unit/SumTest.php', 'testAddsIntegers');
        $withDataset = new TestId('tests/Unit/sum.pest.php', 'it sums', 'negative numbers');

        $this->assertSame('tests/Unit/SumTest.php::testAddsIntegers', $plain->toString());
        $this->assertSame('tests/Unit/sum.pest.php::it sums#negative numbers', $withDataset->toString());
    }

    public function testHashIsStableAndDiscriminates(): void
    {
        $a1 = new TestId('tests/A.php', 'one');
        $a2 = new TestId('tests/A.php', 'one');
        $b  = new TestId('tests/A.php', 'two');

        $this->assertSame($a1->hash(), $a2->hash());
        $this->assertNotSame($a1->hash(), $b->hash());
        $this->assertNotSame('', $a1->hash());
    }

    public function testEquality(): void
    {
        $a = new TestId('tests/A.php', 'one', 'row');
        $b = new TestId('tests/A.php', 'one', 'row');
        $c = new TestId('tests/A.php', 'one');

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals($c));
    }
}
