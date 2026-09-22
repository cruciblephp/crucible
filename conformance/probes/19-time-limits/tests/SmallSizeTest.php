<?php

declare(strict_types=1);

namespace CrucibleConformance\TimeLimits;

use PHPUnit\Framework\Attributes\Small;
use PHPUnit\Framework\TestCase;

use function sleep;

#[Small]
final class SmallSizeTest extends TestCase
{
    // Twice the documented small limit, so the verdict is never a boundary race.
    public function testOverrunsTheSmallLimit(): void
    {
        sleep(2);

        $this->assertTrue(true);
    }

    public function testStaysWithinTheSmallLimit(): void
    {
        $this->assertTrue(true);
    }
}
