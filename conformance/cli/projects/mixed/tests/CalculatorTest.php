<?php

declare(strict_types=1);

use LucianoPereira\Crucible\Attributes\Group;
use LucianoPereira\Crucible\Framework\TestCase;

final class CalculatorTest extends TestCase
{
    public function testAdds(): void
    {
        self::assertSame(4, 2 + 2);
    }

    #[Group('slow')]
    public function testMultiplies(): void
    {
        self::assertSame(6, 2 * 3);
    }
}
