<?php

declare(strict_types=1);

use LucianoPereira\Crucible\Framework\TestCase;

final class MixedOutcomesTest extends TestCase
{
    public function testPasses(): void
    {
        self::assertTrue(true);
    }

    public function testFails(): void
    {
        self::assertSame(1, 2);
    }

    public function testErrors(): void
    {
        throw new RuntimeException('boom');
    }

    public function testSkips(): void
    {
        self::markTestSkipped('not today');
    }
}
