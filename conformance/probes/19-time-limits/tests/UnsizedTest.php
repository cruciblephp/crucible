<?php

declare(strict_types=1);

namespace CrucibleConformance\TimeLimits;

use PHPUnit\Framework\TestCase;

use function sleep;

final class UnsizedTest extends TestCase
{
    // No size attribute: shows whether an undeclared test is limited at all.
    public function testOverrunsTheSmallLimit(): void
    {
        sleep(2);

        $this->assertTrue(true);
    }
}
