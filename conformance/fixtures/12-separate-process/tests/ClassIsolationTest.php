<?php

declare(strict_types=1);

namespace CrucibleConformance\SeparateProcess;

use PHPUnit\Framework\Attributes\RunClassInSeparateProcess;
use PHPUnit\Framework\TestCase;

#[RunClassInSeparateProcess]
final class ClassIsolationTest extends TestCase
{
    public static int $counter = 0;

    public function testFirstIncrementsTheCounter(): void
    {
        self::$counter++;

        $this->assertSame(1, self::$counter);
    }

    public function testSecondSeesTheFirstsIncrement(): void
    {
        // One process for the whole class: state persists within it.
        self::$counter++;

        $this->assertSame(2, self::$counter);
    }
}
