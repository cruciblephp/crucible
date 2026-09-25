<?php

declare(strict_types=1);

namespace CrucibleConformance\Lifecycle;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\Attributes\Before;
use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\Attributes\PreCondition;
use PHPUnit\Framework\TestCase;

/**
 * The attribute hooks against the template methods of their phase, by
 * priority and at a tie. The first test records one whole lifecycle;
 * the second asserts it, so the order is compared, not just the outcome.
 */
trait ResetsFixture
{
    #[Before]
    protected function resetFixture(): void
    {
        HookOrderTest::$log[] = 'trait before';
    }

    #[After]
    protected function releaseFixture(): void
    {
        HookOrderTest::$log[] = 'trait after';
    }
}

final class HookOrderTest extends TestCase
{
    use ResetsFixture;

    /** @var list<string> */
    public static array $log = [];

    protected function setUp(): void
    {
        self::$log[] = 'setUp';
    }

    #[Before]
    protected function beforeAtZero(): void
    {
        self::$log[] = 'before 0';
    }

    #[Before(priority: 5)]
    protected function beforeAtFive(): void
    {
        self::$log[] = 'before 5';
    }

    #[Before(priority: -1)]
    protected function beforeBelowZero(): void
    {
        self::$log[] = 'before -1';
    }

    protected function assertPreConditions(): void
    {
        self::$log[] = 'assertPreConditions';
    }

    #[PreCondition]
    protected function preConditionAtZero(): void
    {
        self::$log[] = 'preCondition 0';
    }

    #[PreCondition(priority: -1)]
    protected function preConditionBelowZero(): void
    {
        self::$log[] = 'preCondition -1';
    }

    protected function assertPostConditions(): void
    {
        self::$log[] = 'assertPostConditions';
    }

    #[PostCondition]
    protected function postConditionAtZero(): void
    {
        self::$log[] = 'postCondition 0';
    }

    #[PostCondition(priority: 1)]
    protected function postConditionAtOne(): void
    {
        self::$log[] = 'postCondition 1';
    }

    protected function tearDown(): void
    {
        self::$log[] = 'tearDown';
    }

    #[After]
    protected function afterAtZero(): void
    {
        self::$log[] = 'after 0';
    }

    #[After(priority: 5)]
    protected function afterAtFive(): void
    {
        self::$log[] = 'after 5';
    }

    #[After(priority: -1)]
    protected function afterBelowZero(): void
    {
        self::$log[] = 'after -1';
    }

    public function testRecordsOneLifecycle(): void
    {
        self::$log[] = 'test';

        $this->assertTrue(true);
    }

    public function testTheLifecycleRanInTheIncumbentsOrder(): void
    {
        $recorded = [];

        foreach (self::$log as $entry) {
            $recorded[] = $entry;

            if ($entry === 'after -1') {
                break;
            }
        }

        $this->assertSame(
            [
                'before 5',
                'trait before',
                'before 0',
                'setUp',
                'before -1',
                'preCondition 0',
                'assertPreConditions',
                'preCondition -1',
                'test',
                'postCondition 1',
                'assertPostConditions',
                'postCondition 0',
                'after 5',
                'tearDown',
                'after 0',
                'trait after',
                'after -1',
            ],
            $recorded,
        );
    }
}
