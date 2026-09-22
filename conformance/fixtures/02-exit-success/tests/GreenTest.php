<?php

declare(strict_types=1);

namespace CrucibleConformance\ExitSuccess;

use PHPUnit\Framework\TestCase;

final class GreenTest extends TestCase
{
    public function testOne(): void
    {
        $this->assertTrue(true);
    }

    public function testTwo(): void
    {
        $this->assertNotSame(1, 2);
    }
}
