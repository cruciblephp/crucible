<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Framework;

use AssertionError;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\AllowMockObjectsWithoutExpectations;
use LucianoPereira\Crucible\Double\DoubleSpecification;
use LucianoPereira\Crucible\Double\InvocationCount;
use LucianoPereira\Crucible\Double\MockBuilder;
use LucianoPereira\Crucible\Double\Mocked;
use LucianoPereira\Crucible\Double\Mockery\MockeryContainer;
use LucianoPereira\Crucible\Double\TestDoubles;
use LucianoPereira\Crucible\Reporting\PrettyName;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

use function preg_match;
use function sprintf;
use function str_contains;
use function trigger_error;

use const E_USER_NOTICE;

/**
 * The xUnit test case of the PHPUnit 13 spec: per-test lifecycle
 * (fresh instance, setUp/tearDown), class-level hooks, exception
 * expectations, and the assertion API inherited from Assert.
 *
 * invokeTest() is Crucible's internal orchestration entry — dialect
 * frontends bind it into a TestDefinition closure; the runner
 * classifies whatever it throws.
 */
abstract class TestCase extends Assert
{
    /** @var ?class-string<Throwable> */
    private ?string $expectedException = null;

    private ?string $expectedExceptionMessage = null;

    /** @var ?non-empty-string */
    private ?string $expectedExceptionMessageRegularExpression = null;

    private int|string|null $expectedExceptionCode = null;

    private bool $doesNotPerformAssertions = false;

    private ?TestDoubles $doubles = null;

    private string $testName = '';

    /** @var ?non-empty-string */
    private ?string $testDataset = null;

    public static function setUpBeforeClass(): void {}

    public static function tearDownAfterClass(): void {}

    protected function setUp(): void {}

    /** Runs after setUp() and before the test: assertions every test of the class shares. */
    protected function assertPreConditions(): void {}

    /** Runs after a test that completed and before tearDown(): assertions every test shares. */
    protected function assertPostConditions(): void {}

    protected function tearDown(): void {}

    /**
     * The seam a subclass uses to replace an error before it is
     * reported: whatever a test, a before hook or setUp() throws, other
     * than a failure, a skip or an incomplete, passes through here once.
     * Frameworks override it (Orchestra Testbench adds the request that
     * produced a Laravel exception), so it must exist to be overridden.
     */
    protected function transformException(Throwable $t): Throwable
    {
        return $t;
    }

    /**
     * Runs one test method through the per-test lifecycle. Internal:
     * called by dialect frontends, not by user code. Returns the test
     * method's return value (consumed by #[Depends] injection).
     *
     * The hook lists carry setUp(), the condition templates and
     * tearDown() at their priority-0 place (HookPlan). A failure in the
     * before phase ends the test without the after phase, as a failing
     * setUp() always has; once the test body is entered, the after
     * phase always runs.
     *
     * @param non-empty-string  $methodName
     * @param list<mixed>       $arguments
     * @param ?non-empty-string $dataset
     */
    final public function invokeTest(string $methodName, array $arguments = [], ?HookPlan $hooks = null, ?string $dataset = null): mixed
    {
        $hooks ??= new HookPlan();
        $this->nameTest($methodName, $dataset);

        try {
            foreach ($hooks->before as $hook) {
                $this->{$hook}();
            }

            foreach ($hooks->preConditions as $hook) {
                $this->{$hook}();
            }
        } catch (Throwable $throwable) {
            throw $this->transformed($throwable);
        }

        try {
            $result = $this->{$methodName}(...$arguments);
            $this->verifyNoExpectedExceptionWasMissed();
            $this->verifyTestDoubles($this->allowsExpectationlessMocks($methodName));

            foreach ($hooks->postConditions as $hook) {
                $this->{$hook}();
            }

            return $result;
        } catch (AssertionFailedError|SkippedTestError|IncompleteTestError $outcome) {
            // An expected exception may itself be an assertion failure
            // (testing a test framework does exactly this).
            if ($outcome instanceof AssertionFailedError && $this->expectsException()) {
                $this->verifyThrowableMatchesExpectation($outcome);

                return null;
            }

            throw $outcome;
        } catch (Throwable $throwable) {
            if (!$this->expectsException()) {
                throw $this->transformed($throwable);
            }

            $this->verifyThrowableMatchesExpectation($throwable);

            return null;
        } finally {
            foreach ($hooks->after as $hook) {
                $this->{$hook}();
            }
        }
    }

    /**
     * An error goes through transformException(); a failure, a skip or
     * an incomplete is an outcome, not an error, and passes unchanged.
     */
    private function transformed(Throwable $throwable): Throwable
    {
        if ($throwable instanceof AssertionFailedError
            || $throwable instanceof AssertionError
            || $throwable instanceof SkippedTestError
            || $throwable instanceof IncompleteTestError) {
            return $throwable;
        }

        return $this->transformException($throwable);
    }

    final public function expectsNoAssertions(): bool
    {
        return $this->doesNotPerformAssertions;
    }

    /**
     * The current test method's name — set at the start of
     * invokeTest(), so it is available throughout setUp(), the test
     * body, and tearDown(). getName() is the pre-PHPUnit-10 alias,
     * kept since real-world suites still mix both (D-019's whole
     * point is running that code unmodified).
     */
    final public function name(): string
    {
        return $this->testName;
    }

    final public function getName(): string
    {
        return $this->testName;
    }

    /**
     * The name with the spec's dataset spelling appended, and the bare
     * name when the test takes no dataset. ✓ Measured against phpunit
     * 13.3.1: `testPositional with data set #0`, `testLabelled with
     * data set "first row"`, `testPlain`.
     */
    final public function nameWithDataSet(): string
    {
        return $this->testDataset === null
            ? $this->testName
            : $this->testName . PrettyName::dataset($this->testDataset);
    }

    /**
     * Names the running test. Internal, like invokeTest(): the phpunit
     * dialect gets this through invokeTest(), and a dialect that runs
     * its own lifecycle (PestBuilder) calls it directly.
     *
     * @param ?non-empty-string $dataset
     */
    final public function nameTest(string $name, ?string $dataset = null): void
    {
        $this->testName    = $name;
        $this->testDataset = $dataset;
    }

    // --- Expectations -------------------------------------------------------

    /**
     * @param class-string<Throwable> $exception
     */
    final protected function expectException(string $exception): void
    {
        $this->expectedException = $exception;
    }

    final protected function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    /**
     * @param non-empty-string $regularExpression
     */
    final protected function expectExceptionMessageMatches(string $regularExpression): void
    {
        $this->expectedExceptionMessageRegularExpression = $regularExpression;
    }

    final protected function expectExceptionCode(int|string $code): void
    {
        $this->expectedExceptionCode = $code;
    }

    final protected function expectNotToPerformAssertions(): void
    {
        $this->doesNotPerformAssertions = true;
    }

    // --- Test doubles -----------------------------------------------------------

    /**
     * @template T of object
     *
     * @param class-string<T> $originalClassName
     *
     * @return T&Mocked
     */
    final protected function createMock(string $originalClassName): object
    {
        /** @var T&Mocked */
        return $this->testDoubles()->fromSpecification(
            new DoubleSpecification([$originalClassName], mergeConfigurations: true, prefixArgumentMatching: true),
            advisesExpectations: true,
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $originalClassName
     *
     * @return T&Mocked
     */
    final protected function createStub(string $originalClassName): object
    {
        return $this->testDoubles()->create($originalClassName);
    }

    /**
     * @template T of object
     *
     * @param class-string<T>              $originalClassName
     * @param array<non-empty-string, mixed> $configuration method => return value
     *
     * @return T&Mocked
     */
    final protected function createConfiguredMock(string $originalClassName, array $configuration): object
    {
        $mock = $this->createMock($originalClassName);

        foreach ($configuration as $method => $value) {
            $mock->method($method)->willReturn($value);
        }

        return $mock;
    }

    /**
     * @template T of object
     *
     * @param class-string<T>              $originalClassName
     * @param array<non-empty-string, mixed> $configuration method => return value
     *
     * @return T&Mocked
     */
    final protected function createConfiguredStub(string $originalClassName, array $configuration): object
    {
        $stub = $this->createStub($originalClassName);

        foreach ($configuration as $method => $value) {
            $stub->method($method)->willReturn($value);
        }

        return $stub;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return MockBuilder<T>
     */
    final protected function getMockBuilder(string $className): MockBuilder
    {
        return new MockBuilder($this->testDoubles(), $className);
    }

    /**
     * Stubs are mocks in Crucible (D-017): the stub builder is the mock
     * builder, with getStub()/setStubClassName() as extra spellings.
     *
     * @template T of object
     *
     * @param class-string<T> $className
     *
     * @return MockBuilder<T>
     */
    final protected function getStubBuilder(string $className): MockBuilder
    {
        return $this->getMockBuilder($className);
    }

    /**
     * The listed methods are doubled; everything else runs the real
     * implementation. The original constructor is never called (spec
     * behavior, observed).
     *
     * @template T of object
     *
     * @param class-string<T>        $originalClassName
     * @param list<non-empty-string> $methods
     *
     * @return T&Mocked
     */
    final protected function createPartialMock(string $originalClassName, array $methods): object
    {
        /** @var T&Mocked */
        return $this->testDoubles()->fromSpecification(
            new DoubleSpecification([$originalClassName], $methods, mergeConfigurations: true, prefixArgumentMatching: true),
            advisesExpectations: true,
        );
    }

    /**
     * @param non-empty-list<class-string> $interfaces
     *
     * @return Mocked
     */
    final protected function createMockForIntersectionOfInterfaces(array $interfaces): object
    {
        return $this->testDoubles()->fromSpecification(
            new DoubleSpecification($interfaces, mergeConfigurations: true, prefixArgumentMatching: true),
            advisesExpectations: true,
        );
    }

    /**
     * @param non-empty-list<class-string> $interfaces
     *
     * @return Mocked
     */
    final protected function createStubForIntersectionOfInterfaces(array $interfaces): object
    {
        return $this->createMockForIntersectionOfInterfaces($interfaces);
    }

    /**
     * Internal: end-of-test double settlement — expectation
     * verification plus the expectation-less-mock advisory (a notice,
     * never an outcome). Called by invokeTest and by dialect frontends
     * that orchestrate the lifecycle themselves (D-046).
     */
    final public function verifyTestDoubles(bool $expectationlessMocksAllowed = false): void
    {
        $this->doubles?->verify();

        // Mockery-grammar doubles settle in the same window — their
        // Mockery::close() is a spelling for this call (D-060).
        MockeryContainer::settle();

        if ($expectationlessMocksAllowed || !$this->doubles instanceof TestDoubles) {
            return;
        }

        foreach ($this->doubles->expectationlessMocks() as $target) {
            trigger_error(sprintf(
                'The mock for %s ended the test without any expectation; create a stub when none are needed, or add #[AllowMockObjectsWithoutExpectations].',
                $target,
            ), E_USER_NOTICE);
        }
    }

    private function allowsExpectationlessMocks(string $methodName): bool
    {
        return (new ReflectionMethod($this, $methodName))->getAttributes(AllowMockObjectsWithoutExpectations::class) !== []
            || (new ReflectionClass($this))->getAttributes(AllowMockObjectsWithoutExpectations::class) !== [];
    }

    final protected function any(): InvocationCount
    {
        return InvocationCount::any();
    }

    final protected function never(): InvocationCount
    {
        return InvocationCount::never();
    }

    final protected function once(): InvocationCount
    {
        return InvocationCount::once();
    }

    final protected function exactly(int $count): InvocationCount
    {
        return InvocationCount::exactly($count);
    }

    final protected function atLeastOnce(): InvocationCount
    {
        return InvocationCount::atLeastOnce();
    }

    final protected function atLeast(int $count): InvocationCount
    {
        return InvocationCount::atLeast($count);
    }

    final protected function atMost(int $count): InvocationCount
    {
        return InvocationCount::atMost($count);
    }

    private function testDoubles(): TestDoubles
    {
        return $this->doubles ??= new TestDoubles();
    }

    // --- Explicit outcomes -----------------------------------------------------

    final protected function markTestSkipped(string $message = ''): never
    {
        throw new SkippedTestError($message);
    }

    final protected function markTestIncomplete(string $message = ''): never
    {
        throw new IncompleteTestError($message);
    }

    // --- Internals ----------------------------------------------------------------

    private function expectsException(): bool
    {
        return $this->expectedException !== null
            || $this->expectedExceptionMessage !== null
            || $this->expectedExceptionMessageRegularExpression !== null
            || $this->expectedExceptionCode !== null;
    }

    private function verifyNoExpectedExceptionWasMissed(): void
    {
        if (!$this->expectsException()) {
            return;
        }

        $expected = $this->expectedException ?? Throwable::class;

        // Disarm before failing: the missed-expectation failure itself
        // must never re-enter expectation matching (AssertionFailedError
        // extends RuntimeException — expecting RuntimeException would
        // otherwise swallow this failure; found by the conformance suite).
        $this->expectedException                         = null;
        $this->expectedExceptionMessage                  = null;
        $this->expectedExceptionMessageRegularExpression = null;
        $this->expectedExceptionCode                     = null;

        self::fail(sprintf(
            'Failed asserting that exception of type "%s" is thrown.',
            $expected,
        ));
    }

    private function verifyThrowableMatchesExpectation(Throwable $throwable): void
    {
        if (!$this->expectsException()) {
            throw $throwable;
        }

        if ($this->expectedException !== null && !$throwable instanceof $this->expectedException) {
            throw new AssertionFailedError(sprintf(
                'Failed asserting that exception of type "%s" matches expected exception "%s". Message was: "%s".',
                $throwable::class,
                $this->expectedException,
                $throwable->getMessage(),
            ));
        }

        if ($this->expectedExceptionMessage !== null && !str_contains($throwable->getMessage(), $this->expectedExceptionMessage)) {
            throw new AssertionFailedError(sprintf(
                'Failed asserting that exception message "%s" contains "%s".',
                $throwable->getMessage(),
                $this->expectedExceptionMessage,
            ));
        }

        if ($this->expectedExceptionMessageRegularExpression !== null && preg_match($this->expectedExceptionMessageRegularExpression, $throwable->getMessage()) !== 1) {
            throw new AssertionFailedError(sprintf(
                'Failed asserting that exception message "%s" matches "%s".',
                $throwable->getMessage(),
                $this->expectedExceptionMessageRegularExpression,
            ));
        }

        if ($this->expectedExceptionCode !== null && (string) $throwable->getCode() !== (string) $this->expectedExceptionCode) {
            throw new AssertionFailedError(sprintf(
                'Failed asserting that exception code "%s" is equal to "%s".',
                (string) $throwable->getCode(),
                (string) $this->expectedExceptionCode,
            ));
        }

        // The expectation itself counts as a verified assertion.
        self::assertThat(true, new \LucianoPereira\Crucible\Assert\Constraint\IsTrue());
    }
}
