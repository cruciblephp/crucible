<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use LucianoPereira\Crucible\Assert\Assert;
use ReflectionClass;
use Throwable;

use function class_exists;
use function interface_exists;
use function sprintf;

/**
 * Real PHPUnit's own $this->expectException()/expectExceptionMessage()/
 * expectExceptionMessageMatches()/expectExceptionCode()
 * (PHPUnit\Framework\Assert, inherited by any real PHPUnit-descended
 * TestCase) set 4 private instance properties that only real
 * PHPUnit's own runner (TestCase::runTest()) ever reads — PestBuilder
 * doesn't delegate to that runner at all, so a test body calling
 * these on a real PHPUnit-descended uses() class had its expected
 * exception propagate as a genuine, uncaught error instead of being
 * recognized as "expected, passes." Found against a real
 * spatie/laravel-data benchmark run
 * (tests/Attributes/Validation/RulesTest.php's own
 * $this->expectException($exception) pattern — 4 real occurrences,
 * confirmed by reading real phpunit/phpunit's own TestCase::runTest()/
 * shouldExceptionExpectationsBeVerified()/verifyExceptionExpectations()/
 * expectedExceptionWasNotRaised(), not guessed).
 *
 * Reads the 4 properties by name via reflection — string names only,
 * never a typed reference to PHPUnit\Framework\TestCase itself — so
 * unlike RealPhpUnitBootstrap/RealPhpUnitHookAttributes/
 * RealPhpUnitOutcomes, this file needs no phpstan exclusion at all.
 * Verifies using Crucible's own Assert, mirroring real PHPUnit's own
 * verifyExceptionExpectations() exactly: instance-of, message
 * contains, message matches regex, code equals — in that order, each
 * only when its corresponding expectation was actually set.
 */
final class RealPhpUnitExceptionExpectations
{
    private const array PROPERTIES = [
        'expectedException',
        'expectedExceptionMessage',
        'expectedExceptionMessageRegExp',
        'expectedExceptionCode',
    ];

    /**
     * Called from the catch block: true means $thrown was verified
     * against the instance's own expectations (the caller should
     * treat this as a pass) — a genuine mismatch throws its own
     * AssertionFailedError from within here instead of returning
     * false, exactly as real PHPUnit's own runner would fail the
     * test rather than silently swallowing a wrong-shaped exception.
     * False only means no expectations were set at all, so the
     * caller's own, unrelated exception handling still applies.
     */
    public static function wasExpected(object $instance, Throwable $thrown): bool
    {
        $expectations = self::read($instance);

        if ($expectations === null) {
            return false;
        }

        [$exception, $message, $messageRegExp, $code] = $expectations;

        if ($exception !== null && (class_exists($exception) || interface_exists($exception))) {
            Assert::assertInstanceOf($exception, $thrown);
        }

        if ($message !== null) {
            Assert::assertStringContainsString($message, $thrown->getMessage());
        }

        if ($messageRegExp !== null && $messageRegExp !== '') {
            Assert::assertMatchesRegularExpression($messageRegExp, $thrown->getMessage());
        }

        if ($code !== null) {
            Assert::assertSame($code, $thrown->getCode());
        }

        return true;
    }

    /**
     * Called from the success path (the test body returned without
     * throwing): fails the test if the instance expected an
     * exception that never came, matching real PHPUnit's own
     * expectedExceptionWasNotRaised().
     */
    public static function verifyNotUnraised(object $instance): void
    {
        $expectations = self::read($instance);

        if ($expectations === null) {
            return;
        }

        [$exception, $message, $messageRegExp, $code] = $expectations;

        Assert::fail(sprintf(
            'Expected exception %s to be thrown, but nothing was.',
            (string) ($exception ?? $message ?? $messageRegExp ?? $code),
        ));
    }

    /**
     * Null when the instance either isn't real-PHPUnit-shaped at all
     * (no such properties exist), or genuinely has none of the 4
     * expectations set — both cases mean "nothing to verify here."
     *
     * @return ?array{0: ?string, 1: ?string, 2: ?string, 3: null|int|string}
     */
    private static function read(object $instance): ?array
    {
        $reflection = self::declaringClass($instance);

        if (!$reflection instanceof ReflectionClass) {
            return null;
        }

        /** @var list<null|int|string> $values */
        $values = [];

        foreach (self::PROPERTIES as $name) {
            $values[] = $reflection->getProperty($name)->getValue($instance);
        }

        if ($values[0] === null && $values[1] === null && $values[2] === null && $values[3] === null) {
            return null;
        }

        /** @var array{0: ?string, 1: ?string, 2: ?string, 3: null|int|string} $values */
        return $values;
    }

    /**
     * ReflectionClass::hasProperty() (and getProperty()) only sees a
     * private property at the exact class level that declares it —
     * confirmed directly, not assumed: a private property one level
     * up already reports hasProperty() false from the child's own
     * ReflectionClass, let alone the several eval()-generated
     * subclass levels PestBuilder's own uses()-class composition
     * (TraitComposer, SnapshotIdentityWrapper) adds on top of a real
     * PHPUnit-descended class. Walking up via getParentClass() finds
     * the actual declaring level; getProperty()->getValue($instance)
     * from that level still correctly reads the real, most-derived
     * instance's own property value (verified directly too — a
     * ReflectionProperty obtained from an ancestor's ReflectionClass
     * reads a descendant instance's value fine).
     *
     * @return ?ReflectionClass<object>
     */
    private static function declaringClass(object $instance): ?ReflectionClass
    {
        $reflection = new ReflectionClass($instance);

        while ($reflection !== false) {
            $hasAll = true;

            foreach (self::PROPERTIES as $name) {
                if (!$reflection->hasProperty($name)) {
                    $hasAll = false;

                    break;
                }
            }

            if ($hasAll) {
                return $reflection;
            }

            $reflection = $reflection->getParentClass();
        }

        return null;
    }
}
