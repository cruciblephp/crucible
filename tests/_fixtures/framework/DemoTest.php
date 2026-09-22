<?php

declare(strict_types=1);

namespace LucianoPereira\Crucible\TestFixtures\Framework;

use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Attributes\DoesNotPerformAssertions;
use LucianoPereira\Crucible\Attributes\Test;
use LucianoPereira\Crucible\Attributes\TestWith;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;

final class DemoTest extends TestCase
{
    public static int $setUpBeforeClassCalls = 0;

    public static int $tearDownAfterClassCalls = 0;

    /** @var list<string> */
    public static array $lifecycle = [];

    public static function setUpBeforeClass(): void
    {
        self::$setUpBeforeClassCalls++;
    }

    public static function tearDownAfterClass(): void
    {
        self::$tearDownAfterClassCalls++;
    }

    protected function setUp(): void
    {
        self::$lifecycle[] = 'setUp';
    }

    protected function tearDown(): void
    {
        self::$lifecycle[] = 'tearDown';
    }

    public function testPasses(): void
    {
        self::$lifecycle[] = 'testPasses';
        $this->assertSame(4, 2 + 2);
    }

    public function testFails(): void
    {
        $this->assertSame('expected', 'actual');
    }

    public function testErrors(): void
    {
        throw new RuntimeException('boom');
    }

    public function testSkips(): void
    {
        $this->markTestSkipped('not on this machine');
    }

    public function testIncomplete(): void
    {
        $this->markTestIncomplete('half done');
    }

    public function testRiskyWithoutAssertions(): void
    {
        // no assertions on purpose
    }

    #[DoesNotPerformAssertions]
    public function testQuietButLegitimate(): void
    {
        // no assertions, declared
    }

    public function testExpectedException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('bad');

        throw new InvalidArgumentException('a bad thing');
    }

    public function testMissedExpectedException(): void
    {
        $this->expectException(InvalidArgumentException::class);
    }

    #[Test]
    public function attributeMarked(): void
    {
        $this->assertTrue(true);
    }

    #[DataProvider('provideSums')]
    public function testSums(int $a, int $b, int $expected): void
    {
        $this->assertSame($expected, $a + $b);
    }

    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function provideSums(): iterable
    {
        yield 'small' => [1, 2, 3];

        yield 'zero' => [0, 0, 0];
    }

    #[TestWith([2, 2, 4])]
    #[TestWith([5, 5, 10], 'fives')]
    public function testInlineRows(int $a, int $b, int $expected): void
    {
        $this->assertSame($expected, $a + $b);
    }

    public function helperNotATest(): void {}
}
