<?php

declare(strict_types=1);

namespace CrucibleConformance\TimeLimits;

use PHPUnit\Framework\Attributes\Medium;
use PHPUnit\Framework\TestCase;

use function sleep;

#[Medium]
final class MediumSizeTest extends TestCase
{
    // Over the small limit, under the medium one: separates per-size limits from one global limit.
    public function testOverrunsTheSmallLimitOnly(): void
    {
        sleep(2);

        $this->assertTrue(true);
    }
}
