<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Framework;

use FrameworkFixtures\RowExists;
use LogicException;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Constraint\LogicalNot;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Framework\HookPlan;
use LucianoPereira\Crucible\Framework\HookPlanner;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\TestFixtures\Framework\AttributedTemplateTest;
use LucianoPereira\Crucible\TestFixtures\Framework\TransformingTest;
use LucianoPereira\Crucible\TestFixtures\Framework\WiredInSetUpTest;
use ReflectionClass;
use RuntimeException;
use Throwable;

use function class_exists;

/**
 * The three places a PHPUnit suite moved to Crucible 1.0.0 broke: the
 * attribute hooks ran after setUp() instead of before it,
 * transformException() did not exist to be overridden, and the
 * incumbent's LogicalNot had no alias. The exact hook order is held
 * against the real runner by conformance/fixtures/09-lifecycle.
 */
#[CoversClass(HookPlanner::class)]
#[CoversClass(TestCase::class)]
#[CoversClass(LogicalNot::class)]
final class LifecycleCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../../_fixtures/framework/HookFixtures.php';
        require_once __DIR__ . '/../../_fixtures/framework/ForeignConstraint.php';

        WiredInSetUpTest::$connection       = [];
        WiredInSetUpTest::$seenByTest       = [];
        AttributedTemplateTest::$setUpCalls = 0;
        TransformingTest::$setUpThrows      = false;
    }

    public function testATraitsBeforeResetRunsAheadOfSetUp(): void
    {
        $this->run(WiredInSetUpTest::class, 'testSeesTheWiring');

        $this->assertSame(['fresh', 'events wired'], WiredInSetUpTest::$seenByTest);
    }

    public function testThePlanPlacesEachTemplateAtPriorityZero(): void
    {
        $plan = HookPlanner::forClass(new ReflectionClass(WiredInSetUpTest::class));

        $this->assertSame(['resetConnection', HookPlan::SET_UP], $plan->before);
        $this->assertSame([HookPlan::PRE_CONDITIONS], $plan->preConditions);
        $this->assertSame([HookPlan::POST_CONDITIONS], $plan->postConditions);
        $this->assertSame([HookPlan::TEAR_DOWN], $plan->after);
    }

    public function testAnAttributeOnATemplateMethodDoesNotRunItTwice(): void
    {
        $this->run(AttributedTemplateTest::class, 'testRuns');

        $this->assertSame(1, AttributedTemplateTest::$setUpCalls);
    }

    public function testAnErrorFromTheTestPassesThroughTransformException(): void
    {
        $thrown = $this->thrownBy(TransformingTest::class, 'testErrors');

        $this->assertInstanceOf(RuntimeException::class, $thrown);
        $this->assertSame('transformed: the test broke', $thrown->getMessage());
    }

    public function testAnErrorFromSetUpPassesThroughTransformException(): void
    {
        TransformingTest::$setUpThrows = true;

        $thrown = $this->thrownBy(TransformingTest::class, 'testErrors');

        $this->assertSame('transformed: setUp broke', $thrown?->getMessage());
    }

    public function testAFailureIsAnOutcomeAndIsNotTransformed(): void
    {
        $this->assertInstanceOf(AssertionFailedError::class, $this->thrownBy(TransformingTest::class, 'testFails'));
    }

    public function testAnExpectedExceptionIsNotTransformed(): void
    {
        $this->assertNull($this->thrownBy(TransformingTest::class, 'testExpectsTheException'));
    }

    public function testTheIncumbentsLogicalNotIsAliased(): void
    {
        PhpUnitCompatibility::load();

        $this->assertTrue(class_exists('PHPUnit\Framework\Constraint\LogicalNot'));
        $this->assertInstanceOf(LogicalNot::class, new \PHPUnit\Framework\Constraint\LogicalNot(new RowExists([])));
    }

    public function testAFrameworkConstraintFailsInTheIncumbentsSentence(): void
    {
        $failure = $this->failureOf(new RowExists([]), 'widgets');

        $this->assertSame('Failed asserting that a row in the table [widgets] matches the attributes {"name":"this is data"}.', $failure);
    }

    public function testItsNegationNegatesTheWordingAndNotTheData(): void
    {
        $failure = $this->failureOf(new LogicalNot(new RowExists(['widgets'])), 'widgets');

        $this->assertSame('Failed asserting that a row in the table [widgets] does not match the attributes {"name":"this is data"}.', $failure);
    }

    public function testTheNegationPasses(): void
    {
        $this->assertThat('widgets', new LogicalNot(new RowExists([])));
    }

    /**
     * @param class-string<TestCase> $class
     * @param non-empty-string       $method
     */
    private function run(string $class, string $method): void
    {
        (new $class())->invokeTest($method, [], HookPlanner::forClass(new ReflectionClass($class)));
    }

    /**
     * @param class-string<TestCase> $class
     * @param non-empty-string       $method
     */
    private function thrownBy(string $class, string $method): ?Throwable
    {
        try {
            $this->run($class, $method);
        } catch (Throwable $thrown) {
            return $thrown;
        }

        return null;
    }

    private function failureOf(\LucianoPereira\Crucible\Assert\Constraint\Constraint $constraint, string $other): string
    {
        try {
            $constraint->evaluate($other);
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        throw new LogicException('The constraint was expected to fail.');
    }
}
