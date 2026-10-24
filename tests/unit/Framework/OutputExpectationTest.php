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
use Error;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Attributes\DataProvider;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;
use Throwable;

use function ob_get_clean;
use function ob_start;

/**
 * The output expectations of the PHPUnit 13 spec (D-155). Each expected
 * outcome, message and assertion count is what PHPUnit 13.4.3 gave the
 * same test.
 */
final class OutputExpectationTest extends TestCase
{
    public function testAMatchingStringPassesAsOneAssertion(): void
    {
        [$failure, $count] = $this->outcomeOf(static function (TestCase $test): void {
            $test->expectOutputString('hello');
            print 'hello';
        });

        $this->assertNull($failure);
        $this->assertSame(1, $count);
    }

    public function testAnotherStringFailsAsAssertSameDoes(): void
    {
        [$failure] = $this->outcomeOf(static function (TestCase $test): void {
            $test->expectOutputString('hello');
            print 'goodbye';
        });

        $this->assertStringStartsWith('Failed asserting that two strings are identical.', (string) $failure);
    }

    public function testAPatternIsMatchedInTheSpecsWords(): void
    {
        [$failure] = $this->outcomeOf(static function (TestCase $test): void {
            $test->expectOutputRegex('/^h.llo$/');
            print 'nope';
        });

        $this->assertSame('Failed asserting that \'nope\' matches PCRE pattern "/^h.llo$/".', $failure);
    }

    public function testASecondExpectationKeepsTheFirst(): void
    {
        [$failure] = $this->outcomeOf(static function (TestCase $test): void {
            $test->expectOutputRegex('/^a$/');
            $test->expectOutputRegex('/^b$/');
            print 'b';
        });

        $this->assertSame('Failed asserting that \'b\' matches PCRE pattern "/^a$/".', $failure);
    }

    public function testThePatternIsCheckedBeforeTheStringAndBothCount(): void
    {
        [$failure, $count] = $this->outcomeOf(static function (TestCase $test): void {
            $test->expectOutputString('a');
            $test->expectOutputRegex('/^b$/');
            print 'b';
        });

        $this->assertStringStartsWith('Failed asserting that two strings are identical.', (string) $failure);
        $this->assertSame(2, $count);
    }

    public function testAnExceptionIsTheOutcomeAndTheOutputIsNotCompared(): void
    {
        [$failure, $count] = $this->outcomeOf(static function (TestCase $test): void {
            $test->expectOutputString('never');
            print 'partial';

            throw new RuntimeException('boom');
        });

        $this->assertSame('boom', $failure);
        $this->assertSame(0, $count);
    }

    public function testReadingTheOutputMakesItExpected(): void
    {
        $seen = null;

        [$failure] = $this->outcomeOf(static function (TestCase $test) use (&$seen): void {
            print 'seen';
            $seen = [$test->getActualOutputForAssertion(), $test->expectsOutput(), $test->hasUnexpectedOutput(), Assert::outputExpected()];
        });

        $this->assertNull($failure);
        $this->assertSame(['seen', true, false, true], $seen);
    }

    public function testOutputNobodyExpectedIsUnexpected(): void
    {
        $seen = null;

        $this->outcomeOf(static function (TestCase $test) use (&$seen): void {
            print 'loose';
            $seen = [$test->output(), $test->expectsOutput(), $test->hasUnexpectedOutput()];
        });

        $this->assertSame(['loose', false, true], $seen);
    }

    /**
     * output() is served, not declared (D-159): a test class's own output(),
     * private and of another signature here, wins, and the expectations
     * still read the captured output.
     */
    public function testATestClassMayHaveAnOutputMethodOfItsOwn(): void
    {
        $probe = new class extends TestCase {
            public function test_probe(): void
            {
                $this->expectOutputString('printed');
                print 'printed';
                self::assertSame('helper 3', $this->output(static fn(): int => 3));
            }

            /** @param callable(): int $value */
            private function output(callable $value): string
            {
                return 'helper ' . $value();
            }
        };

        ob_start();

        try {
            $probe->invokeTest('test_probe');
        } finally {
            ob_get_clean();
        }

        $this->assertTrue(true);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function typos(): iterable
    {
        yield 'a misspelt assertion' => ['asertSame'];
    }

    /** A dataset, because PHPStan reports a typo it can read, which is the point of keeping it PHP's error. */
    #[DataProvider('typos')]
    public function testAnUnknownMethodIsPhpsOwnError(string $typo): void
    {
        $probe = new class extends TestCase {};

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Call to undefined method ' . $probe::class . '::' . $typo . '()');

        $probe->{$typo}(1, 1);
    }

    /**
     * Runs the body as a test of its own, in a buffer as the runner opens one.
     *
     * @param Closure(TestCase): void $body
     *
     * @return array{?string, int} the failure or error message, and the assertions the test counted
     */
    private function outcomeOf(Closure $body): array
    {
        $probe = new class ($body) extends TestCase {
            /** @param Closure(TestCase): void $body */
            public function __construct(private readonly Closure $body) {}

            public function test_probe(): void
            {
                ($this->body)($this);
            }
        };

        $before = Assert::assertionCount();
        Assert::forgetNoAssertionsDeclaration();
        ob_start();

        try {
            $probe->invokeTest('test_probe');
            $failure = null;
        } catch (Throwable $thrown) {
            $failure = $thrown->getMessage();
        } finally {
            ob_get_clean();
        }

        return [$failure, Assert::assertionCount() - $before];
    }
}
