<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\CurrentTest;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;

#[CoversClass(CurrentTest::class)]
final class CurrentTestTest extends TestCase
{
    protected function tearDown(): void
    {
        CurrentTest::set(null);
    }

    public function testGetThrowsWhenNoTestIsRunning(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('No test is currently running');

        CurrentTest::get();
    }

    public function testGetReturnsWhateverWasSet(): void
    {
        $instance = new stdClass();

        CurrentTest::set($instance);

        self::assertSame($instance, CurrentTest::get());
    }

    public function testSetNullClearsIt(): void
    {
        CurrentTest::set(new stdClass());
        CurrentTest::set(null);

        $this->expectException(ConfigurationException::class);

        CurrentTest::get();
    }

    /**
     * Real Laravel testing-trait methods are `protected` — meant for
     * `$this->` inside a TestCase, not for a global Pest\Laravel\*
     * function. A plain `->method()` call would fatal with "Call to
     * protected method ... from global scope", which is why this goes
     * through reflection.
     */
    public function testCallReachesAProtectedMethodOnTheRunningTest(): void
    {
        CurrentTest::set(new class {
            protected function hidden(string $suffix): string
            {
                return 'reached:' . $suffix;
            }
        });

        self::assertSame('reached:ok', CurrentTest::call('hidden', ['ok']));
    }

    /**
     * Reflection returns mixed, so a proxy declaring a concrete return
     * type is making a claim nothing checks. This is where it is
     * checked, once, rather than in every proxy.
     */
    public function testCallReturningHandsBackAValueOfThePromisedType(): void
    {
        $expected = new stdClass();

        CurrentTest::set(new readonly class ($expected) {
            public function __construct(private stdClass $value) {}

            protected function make(): stdClass
            {
                return $this->value;
            }
        });

        self::assertSame($expected, CurrentTest::callReturning(stdClass::class, 'make', []));
    }

    public function testCallReturningNamesTheMethodTheTypeAndWhatItGotInstead(): void
    {
        CurrentTest::set(new class {
            protected function make(): string
            {
                return 'not an object';
            }
        });

        try {
            CurrentTest::callReturning(stdClass::class, 'make', []);
            self::fail('a wrong return type must be reported where it happens, not inside the caller');
        } catch (ConfigurationException $refusal) {
            self::assertStringContainsString('make()', $refusal->getMessage());
            self::assertStringContainsString(stdClass::class, $refusal->getMessage());
            self::assertStringContainsString('string', $refusal->getMessage());
        }
    }

    /**
     * The container's swap/instance surface promises only "an object",
     * typed by the caller's own argument rather than by a class this
     * bridge could name.
     */
    public function testCallReturningObjectAcceptsAnyObject(): void
    {
        $expected = new stdClass();

        CurrentTest::set(new readonly class ($expected) {
            public function __construct(private stdClass $value) {}

            protected function swap(): object
            {
                return $this->value;
            }
        });

        self::assertSame($expected, CurrentTest::callReturningObject('swap', []));
    }

    public function testCallReturningObjectRefusesANonObject(): void
    {
        CurrentTest::set(new class {
            protected function swap(): int
            {
                return 7;
            }
        });

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('to return an object, got int');

        CurrentTest::callReturningObject('swap', []);
    }
}
