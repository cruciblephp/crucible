<?php

declare(strict_types=1);

namespace CrucibleConformance\Ordering;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderingTest extends TestCase
{
    public function testAlpha(): void
    {
        $this->assertTrue(true);
    }

    public function testBravo(): void
    {
        $this->assertSame(2, 1 + 1);
    }

    #[DataProvider('provideRows')]
    public function testCharlie(int $value): void
    {
        $this->assertGreaterThan(0, $value);
    }

    public static function provideRows(): iterable
    {
        yield 'one' => [1];
        yield 'two' => [2];
    }

    public function testDelta(): void
    {
        $this->assertNotNull('x');
    }
}
