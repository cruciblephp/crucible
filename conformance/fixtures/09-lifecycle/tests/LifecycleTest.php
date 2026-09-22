<?php

declare(strict_types=1);

namespace CrucibleConformance\Lifecycle;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function count;

final class LifecycleTest extends TestCase
{
    /** @var list<string> */
    public static array $log = [];

    public static function setUpBeforeClass(): void
    {
        self::$log[] = 'beforeClass';
    }

    protected function setUp(): void
    {
        self::$log[] = 'setUp';
    }

    #[Before]
    protected function extraBefore(): void
    {
        self::$log[] = 'before';
    }

    #[After]
    protected function extraAfter(): void
    {
        self::$log[] = 'after';
    }

    protected function tearDown(): void
    {
        self::$log[] = 'tearDown';
    }

    public function testFirstSeesClassAndTestSetup(): void
    {
        $this->assertContains('beforeClass', self::$log);
        $this->assertContains('setUp', self::$log);
        $this->assertContains('before', self::$log);
    }

    public function testSecondSeesFirstTeardown(): void
    {
        $this->assertContains('after', self::$log);
        $this->assertContains('tearDown', self::$log);
        $this->assertSame(1, count(array_keys(self::$log, 'beforeClass', true)));
    }
}
