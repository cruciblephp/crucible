<?php

declare(strict_types=1);

namespace CrucibleConformance\Filter;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/*
 * Run with --filter=testKeep on both runners (options.json). The
 * dropped tests FAIL if executed, so a broken filter cannot conform.
 */
final class FilterTest extends TestCase
{
    public function testKeepAlpha(): void
    {
        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideRows(): iterable
    {
        yield 'one' => [1];
        yield 'two' => [2];
    }

    #[DataProvider('provideRows')]
    public function testKeepRows(int $value): void
    {
        $this->assertGreaterThan(0, $value);
    }

    public function testDropCharlie(): void
    {
        $this->fail('A filtered-out test must never run.');
    }
}
