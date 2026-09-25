<?php

declare(strict_types=1);

namespace LucianoPereira\Crucible\TestFixtures\Framework;

use LogicException;
use LucianoPereira\Crucible\Attributes\Before;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;
use Throwable;

/** A trait's reset, the shape of a package's RefreshesArrayStore. */
trait ResetsConnection
{
    #[Before]
    protected function resetConnection(): void
    {
        WiredInSetUpTest::$connection = ['fresh'];
    }
}

final class WiredInSetUpTest extends TestCase
{
    use ResetsConnection;

    /** @var list<string> */
    public static array $connection = [];

    /** @var list<string> */
    public static array $seenByTest = [];

    protected function setUp(): void
    {
        self::$connection[] = 'events wired';
    }

    public function testSeesTheWiring(): void
    {
        self::$seenByTest = self::$connection;
    }
}

final class AttributedTemplateTest extends TestCase
{
    public static int $setUpCalls = 0;

    #[Before]
    protected function setUp(): void
    {
        self::$setUpCalls++;
    }

    public function testRuns(): void {}
}

/** The shape of Orchestra Testbench's override. */
final class TransformingTest extends TestCase
{
    public static bool $setUpThrows = false;

    protected function setUp(): void
    {
        if (self::$setUpThrows) {
            throw new LogicException('setUp broke');
        }
    }

    protected function transformException(Throwable $t): Throwable
    {
        return new RuntimeException('transformed: ' . $t->getMessage(), 0, $t);
    }

    public function testErrors(): void
    {
        throw new LogicException('the test broke');
    }

    public function testFails(): void
    {
        $this->assertSame(1, 2);
    }

    public function testExpectsTheException(): void
    {
        $this->expectException(LogicException::class);

        throw new LogicException('expected');
    }
}
