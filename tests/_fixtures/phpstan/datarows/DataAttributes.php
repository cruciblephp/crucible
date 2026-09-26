<?php

declare(strict_types=1);

namespace LucianoPereira\Crucible\Tests\Fixtures\PHPStan\DataRows;

use LucianoPereira\Crucible\Attributes\Check;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

final class Temperature
{
    #[Check([0.0], returns: 32.0)]
    #[Check(['40'], returns: 104.0)]
    #[Check(['celsius' => 40.0], returns: 'hot')]
    #[Check([], returns: 1.0)]
    #[Check([-300.0], throws: \InvalidArgumentException::class)]
    public static function toFahrenheit(float $celsius): float
    {
        return $celsius * 9 / 5 + 32;
    }
}

final class AddTest extends TestCase
{
    #[TestWith([1, 2, 3])]
    #[TestWith(['1', 2, 3])]
    #[TestWith([1, 2])]
    #[TestWithJson('[1, 2, 3]')]
    #[TestWithJson('[1, "two", 3]')]
    public function testAdds(int $a, int $b, int $sum): void
    {
        self::assertSame($sum, $a + $b);
    }
}
