<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Pest;

use LogicException;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Dialect\Pest\RealPhpUnitExceptionExpectations;
use LucianoPereira\Crucible\Framework\TestCase;
use RuntimeException;
use stdClass;

/**
 * Duck-types real PHPUnit\Framework\TestCase's own 4 private
 * properties exactly (name and type, verified against the real,
 * installed phpunit/phpunit source) — real PHPUnit's own
 * expectException()/expectExceptionMessage()/
 * expectExceptionMessageMatches()/expectExceptionCode() just set
 * these directly, so this fixture's own setters mirror that, letting
 * RealPhpUnitExceptionExpectations be tested without needing
 * phpunit/phpunit installed (same reasoning as every other
 * Real-prefixed test fixture in this suite).
 */
class FakeRealPhpUnitCase
{
    private ?string $expectedException = null;

    private ?string $expectedExceptionMessage = null;

    private ?string $expectedExceptionMessageRegExp = null;

    private int|string|null $expectedExceptionCode = null;

    public function expectException(string $exception): void
    {
        $this->expectedException = $exception;
    }

    public function expectExceptionMessage(string $message): void
    {
        $this->expectedExceptionMessage = $message;
    }

    public function expectExceptionMessageMatches(string $regularExpression): void
    {
        $this->expectedExceptionMessageRegExp = $regularExpression;
    }

    public function expectExceptionCode(int|string $code): void
    {
        $this->expectedExceptionCode = $code;
    }
}

/**
 * Matches the real shape PestBuilder actually hands
 * RealPhpUnitExceptionExpectations: a real PHPUnit-descended
 * uses() class, itself wrapped in one or more eval()-generated
 * subclasses (TraitComposer::compose(), SnapshotIdentityWrapper::wrap())
 * — never the declaring class directly. The bug this class exists to
 * catch: ReflectionClass::hasProperty() only sees a private property
 * at the exact class level that declares it, not an inherited one —
 * confirmed directly against real phpunit/phpunit, this made
 * declaringClass() (then a bare `new ReflectionClass($instance)`)
 * report false for FakeRealPhpUnitCase's own properties from even
 * one level down, so every one of the tests above — all constructing
 * FakeRealPhpUnitCase directly — passed while the real, real-world
 * multi-level-subclassed case silently returned early. Found only
 * against a real benchmark, not this suite alone.
 */
final class ComposedFakeRealPhpUnitCase extends FakeRealPhpUnitCase {}

#[CoversClass(RealPhpUnitExceptionExpectations::class)]
final class RealPhpUnitExceptionExpectationsTest extends TestCase
{
    public function testWasExpectedReturnsFalseWhenNothingWasSet(): void
    {
        self::assertFalse(RealPhpUnitExceptionExpectations::wasExpected(
            new FakeRealPhpUnitCase(),
            new RuntimeException('anything'),
        ));
    }

    public function testWasExpectedReturnsFalseForAnInstanceWithoutThePhpUnitShape(): void
    {
        self::assertFalse(RealPhpUnitExceptionExpectations::wasExpected(
            new stdClass(),
            new RuntimeException('anything'),
        ));
    }

    public function testWasExpectedReturnsTrueWhenTheThrownExceptionMatchesTheExpectedClass(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectException(RuntimeException::class);

        self::assertTrue(RealPhpUnitExceptionExpectations::wasExpected(
            $instance,
            new RuntimeException('the grinder is jammed'),
        ));
    }

    public function testWasExpectedFailsWhenTheThrownExceptionIsTheWrongClass(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectException(LogicException::class);

        $this->expectException(AssertionFailedError::class);

        RealPhpUnitExceptionExpectations::wasExpected($instance, new RuntimeException('wrong shape'));
    }

    public function testWasExpectedChecksTheMessageContains(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectExceptionMessage('grinder is jammed');

        self::assertTrue(RealPhpUnitExceptionExpectations::wasExpected(
            $instance,
            new RuntimeException('the grinder is jammed'),
        ));
    }

    public function testWasExpectedFailsWhenTheMessageDoesNotContain(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectExceptionMessage('grinder is jammed');

        $this->expectException(AssertionFailedError::class);

        RealPhpUnitExceptionExpectations::wasExpected($instance, new RuntimeException('all is well'));
    }

    public function testWasExpectedChecksTheMessageRegularExpression(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectExceptionMessageMatches('/grinder.*jammed/');

        self::assertTrue(RealPhpUnitExceptionExpectations::wasExpected(
            $instance,
            new RuntimeException('the grinder is jammed'),
        ));
    }

    public function testWasExpectedChecksTheExceptionCode(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectExceptionCode(42);

        self::assertTrue(RealPhpUnitExceptionExpectations::wasExpected(
            $instance,
            new RuntimeException('anything', 42),
        ));
    }

    public function testWasExpectedFailsWhenTheCodeDiffers(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectExceptionCode(42);

        $this->expectException(AssertionFailedError::class);

        RealPhpUnitExceptionExpectations::wasExpected($instance, new RuntimeException('anything', 1));
    }

    public function testVerifyNotUnraisedIsANoOpWhenNothingWasExpected(): void
    {
        RealPhpUnitExceptionExpectations::verifyNotUnraised(new FakeRealPhpUnitCase());

        $this->addToAssertionCount(1);
    }

    public function testVerifyNotUnraisedFailsWhenAnExceptionWasExpectedButNoneWasThrown(): void
    {
        $instance = new FakeRealPhpUnitCase();
        $instance->expectException(RuntimeException::class);

        $this->expectException(AssertionFailedError::class);

        RealPhpUnitExceptionExpectations::verifyNotUnraised($instance);
    }

    public function testWasExpectedFindsExpectationsDeclaredOnAnAncestorClass(): void
    {
        $instance = new ComposedFakeRealPhpUnitCase();
        $instance->expectException(RuntimeException::class);

        self::assertTrue(RealPhpUnitExceptionExpectations::wasExpected(
            $instance,
            new RuntimeException('the grinder is jammed'),
        ));
    }
}
