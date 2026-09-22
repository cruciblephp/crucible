<?php

declare(strict_types=1);

namespace CrucibleConformance\Datasets;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\Attributes\TestWithJson;
use PHPUnit\Framework\TestCase;

use function strlen;

final class DatasetsTest extends TestCase
{
    #[DataProvider('provideNamed')]
    public function testNamedRows(int $a, int $b, int $sum): void
    {
        $this->assertSame($sum, $a + $b);
    }

    public static function provideNamed(): iterable
    {
        yield 'small' => [1, 2, 3];
        yield 'zero' => [0, 0, 0];
    }

    #[DataProvider('provideIndexed')]
    public function testIndexedRows(string $word, int $length): void
    {
        $this->assertSame($length, strlen($word));
    }

    public static function provideIndexed(): array
    {
        return [
            ['crucible', 5],
            ['ok', 2],
        ];
    }

    #[TestWith([2, 2, 4])]
    #[TestWith([5, 5, 10], 'fives')]
    public function testInline(int $a, int $b, int $sum): void
    {
        $this->assertSame($sum, $a + $b);
    }

    #[TestWithJson('[3, 4, 7]')]
    public function testJsonRow(int $a, int $b, int $sum): void
    {
        $this->assertSame($sum, $a + $b);
    }
}
