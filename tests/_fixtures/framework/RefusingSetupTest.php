<?php

declare(strict_types=1);

namespace LucianoPereira\Crucible\TestFixtures\Framework;

use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;

/**
 * A TestCase whose setUp() refuses to start — the case the around-test
 * role exists for (D-172): Crucible skips the after phase when setUp()
 * fails, exactly as the incumbent does, so a trait's #[After] cleanup
 * would fall to the next test. What this class records proves the hook
 * saw the failure and the body never ran.
 */
final class RefusingSetupTest extends TestCase
{
    /** @var list<string> */
    public static array $lifecycle = [];

    protected function setUp(): void
    {
        throw new RuntimeException('setUp refused to start');
    }

    protected function tearDown(): void
    {
        self::$lifecycle[] = 'tearDown';
    }

    public function testNeverReachesItsBody(): void
    {
        self::$lifecycle[] = 'body';
    }
}
