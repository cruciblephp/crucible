<?php

declare(strict_types=1);

namespace CrucibleConformance\ExitFailure;

use PHPUnit\Framework\TestCase;

final class RedTest extends TestCase
{
    public function testPasses(): void
    {
        $this->assertTrue(true);
    }

    public function testFails(): void
    {
        $this->assertGreaterThan(10, 5);
    }
}
