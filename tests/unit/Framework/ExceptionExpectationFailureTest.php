<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Framework;

use Closure;
use LogicException;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;
use Throwable;

use function dirname;

/**
 * An exception expectation that is not met fails in PHPUnit's words
 * (D-149). Each expected message is what PHPUnit 13.4.3 printed for the
 * same expectation and the same exception.
 */
final class ExceptionExpectationFailureTest extends TestCase
{
    public function testAnotherMessageIsQuotedAsTheIncumbentExportsIt(): void
    {
        $this->assertSame(
            "Failed asserting that exception message 'something else' contains 'not there'.",
            $this->failureOf(static fn(TestCase $test) => $test->expectExceptionMessage('not there'), new RuntimeException('something else')),
        );
    }

    public function testAMessageThatMissesThePatternIsQuotedTheSameWay(): void
    {
        $this->assertSame(
            "Failed asserting that exception message 'something else' matches '/^nope$/'.",
            $this->failureOf(static fn(TestCase $test) => $test->expectExceptionMessageMatches('/^nope$/'), new RuntimeException('something else')),
        );
    }

    public function testAnotherCodeNamesTheExpectedCode(): void
    {
        $this->assertSame(
            'Failed asserting that 3 is equal to expected exception code 7.',
            $this->failureOf(static fn(TestCase $test) => $test->expectExceptionCode(7), new RuntimeException('x', 3)),
        );
    }

    public function testAStringCodeIsExportedAsAString(): void
    {
        $this->assertSame(
            "Failed asserting that 3 is equal to expected exception code 'A7'.",
            $this->failureOf(static fn(TestCase $test) => $test->expectExceptionCode('A7'), new RuntimeException('x', 3)),
        );
    }

    public function testAnotherTypeSaysWhereTheExceptionCameFrom(): void
    {
        $line    = __LINE__ + 1;
        $thrown  = new LogicException('deep');
        $failure = $this->failureOf(static fn(TestCase $test) => $test->expectException(RuntimeException::class), $thrown);

        $this->assertStringStartsWith(
            'Failed asserting that exception of type "LogicException" matches expected exception "RuntimeException". Message was: "deep" at' . "\n" . __FILE__ . ':' . $line . "\n",
            $failure,
        );
        $this->assertStringEndsWith("\n.", $failure);
        $this->assertStringNotContainsString(dirname(__DIR__, 3) . '/src/', $failure);
    }

    /**
     * @param Closure(TestCase): void $expect
     */
    private function failureOf(Closure $expect, Throwable $thrown): string
    {
        $probe = new class ($expect, $thrown) extends TestCase {
            /** @param Closure(TestCase): void $expect */
            public function __construct(private readonly Closure $expect, private readonly Throwable $thrown) {}

            public function test_probe(): never
            {
                ($this->expect)($this);

                throw $this->thrown;
            }
        };

        try {
            $probe->invokeTest('test_probe');
        } catch (AssertionFailedError $failure) {
            return $failure->getMessage();
        }

        throw new LogicException('The expectation was expected to fail.');
    }
}
