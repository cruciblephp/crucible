<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

use ArrayAccess;
use Closure;
use Countable;
use LucianoPereira\Crucible\Assert\Constraint\ArrayComparison;
use LucianoPereira\Crucible\Assert\Constraint\ArrayHasKey;
use LucianoPereira\Crucible\Assert\Constraint\Callback;
use LucianoPereira\Crucible\Assert\Constraint\Constraint;
use LucianoPereira\Crucible\Assert\Constraint\DirectoryExists;
use LucianoPereira\Crucible\Assert\Constraint\FileExists;
use LucianoPereira\Crucible\Assert\Constraint\GreaterThan;
use LucianoPereira\Crucible\Assert\Constraint\HasCount;
use LucianoPereira\Crucible\Assert\Constraint\IsAnything;
use LucianoPereira\Crucible\Assert\Constraint\IsEmpty;
use LucianoPereira\Crucible\Assert\Constraint\IsEqual;
use LucianoPereira\Crucible\Assert\Constraint\IsFalse;
use LucianoPereira\Crucible\Assert\Constraint\IsFinite;
use LucianoPereira\Crucible\Assert\Constraint\IsIdentical;
use LucianoPereira\Crucible\Assert\Constraint\IsInfinite;
use LucianoPereira\Crucible\Assert\Constraint\IsInstanceOf;
use LucianoPereira\Crucible\Assert\Constraint\IsJson;
use LucianoPereira\Crucible\Assert\Constraint\IsList;
use LucianoPereira\Crucible\Assert\Constraint\IsNan;
use LucianoPereira\Crucible\Assert\Constraint\IsNull;
use LucianoPereira\Crucible\Assert\Constraint\IsReadable;
use LucianoPereira\Crucible\Assert\Constraint\IsTrue;
use LucianoPereira\Crucible\Assert\Constraint\IsType;
use LucianoPereira\Crucible\Assert\Constraint\IsWritable;
use LucianoPereira\Crucible\Assert\Constraint\JsonMatches;
use LucianoPereira\Crucible\Assert\Constraint\LessThan;
use LucianoPereira\Crucible\Assert\Constraint\LogicalNot;
use LucianoPereira\Crucible\Assert\Constraint\MatchesRegularExpression;
use LucianoPereira\Crucible\Assert\Constraint\MatchesShape;
use LucianoPereira\Crucible\Assert\Constraint\ObjectEquals;
use LucianoPereira\Crucible\Assert\Constraint\ObjectHasProperty;
use LucianoPereira\Crucible\Assert\Constraint\StringContains;
use LucianoPereira\Crucible\Assert\Constraint\StringEndsWith;
use LucianoPereira\Crucible\Assert\Constraint\StringEqualsStringIgnoringLineEndings;
use LucianoPereira\Crucible\Assert\Constraint\StringEqualsStringIgnoringWhitespace;
use LucianoPereira\Crucible\Assert\Constraint\StringMatchesFormat;
use LucianoPereira\Crucible\Assert\Constraint\StringStartsWith;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContains;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContainsOnlyInstancesOf;
use LucianoPereira\Crucible\Assert\Constraint\TraversableContainsOnlyType;
use LucianoPereira\Crucible\Assert\Constraint\XmlEqualsXml;
use LucianoPereira\Crucible\Double\Stub;
use LucianoPereira\Crucible\Snapshot\Snapshots;
use Throwable;

use function array_first;
use function array_key_exists;
use function count;
use function debug_backtrace;
use function file_get_contents;
use function is_countable;
use function is_file;
use function is_int;
use function is_readable;
use function is_string;
use function iterator_count;
use function sprintf;

/**
 * The assertion API of the PHPUnit 13 spec, implemented on Crucible's
 * constraint engine. Every method funnels through assertThat(), which
 * is also where assertions are counted.
 *
 * Coverage status vs the ~176-method spec surface is tracked in
 * ROADMAP.md Phase 2.
 */
abstract class Assert
{
    /** @var int<0, max> */
    private static int $count = 0;

    public static function assertThat(mixed $value, Constraint $constraint, string $message = ''): void
    {
        self::$count++;

        $constraint->evaluate($value, $message);
    }

    public static function fail(string $message = ''): never
    {
        self::$count++;

        throw new AssertionFailedError($message !== '' ? $message : 'Failed asserting that a test did not fail.');
    }

    /**
     * Spec method: frameworks layering their own assertion helpers on
     * top (Laravel's TestResponse, for one) report their assertions
     * through this, keeping risky-test detection honest.
     */
    public function addToAssertionCount(int $count): void
    {
        if ($count > 0) {
            self::$count += $count;
        }
    }

    /**
     * Engine-internal: count one satisfied assertion without
     * evaluating anything — the "this observed outcome IS the claim"
     * tick (an expected failure happened, a snapshot was recorded).
     * Introduced by D-049 to replace the assertTrue(true) idiom,
     * which the narrowing extension rightly reports as a tautology.
     * User helpers layering assertions use addToAssertionCount().
     */
    public static function countSatisfiedAssertion(): void
    {
        self::$count++;
    }

    /**
     * @return int<0, max>
     */
    public static function assertionCount(): int
    {
        return self::$count;
    }

    public static function resetAssertionCount(): void
    {
        self::$count = 0;
    }

    // --- Identity & equality --------------------------------------------

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsIdentical($expected), $message);
    }

    public static function assertNotSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsIdentical($expected)), $message);
    }

    public static function assertEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsEqual($expected), $message);
    }

    public static function assertNotEquals(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsEqual($expected)), $message);
    }

    public static function assertEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void
    {
        self::assertThat($actual, new IsEqual($expected, delta: $delta), $message);
    }

    public static function assertNotEqualsWithDelta(mixed $expected, mixed $actual, float $delta, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsEqual($expected, delta: $delta)), $message);
    }

    public static function assertEqualsCanonicalizing(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsEqual($expected, canonicalize: true), $message);
    }

    public static function assertNotEqualsCanonicalizing(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsEqual($expected, canonicalize: true)), $message);
    }

    // --- Booleans & null --------------------------------------------------

    public static function assertTrue(mixed $condition, string $message = ''): void
    {
        self::assertThat($condition, new IsTrue(), $message);
    }

    public static function assertNotTrue(mixed $condition, string $message = ''): void
    {
        self::assertThat($condition, new LogicalNot(new IsTrue()), $message);
    }

    public static function assertFalse(mixed $condition, string $message = ''): void
    {
        self::assertThat($condition, new IsFalse(), $message);
    }

    public static function assertNotFalse(mixed $condition, string $message = ''): void
    {
        self::assertThat($condition, new LogicalNot(new IsFalse()), $message);
    }

    public static function assertNull(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsNull(), $message);
    }

    public static function assertNotNull(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsNull()), $message);
    }

    // --- Emptiness, counts, sizes ----------------------------------------

    public static function assertEmpty(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsEmpty(), $message);
    }

    public static function assertNotEmpty(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsEmpty()), $message);
    }

    /**
     * @param Countable|iterable<mixed> $haystack
     */
    public static function assertCount(int $expectedCount, Countable|iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new HasCount($expectedCount), $message);
    }

    /**
     * @param Countable|iterable<mixed> $haystack
     */
    public static function assertNotCount(int $expectedCount, Countable|iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new HasCount($expectedCount)), $message);
    }

    /**
     * @param Countable|iterable<mixed> $expected
     * @param Countable|iterable<mixed> $actual
     */
    public static function assertSameSize(Countable|iterable $expected, Countable|iterable $actual, string $message = ''): void
    {
        self::assertThat($actual, new HasCount(self::sizeOf($expected)), $message);
    }

    /**
     * @param Countable|iterable<mixed> $expected
     * @param Countable|iterable<mixed> $actual
     */
    public static function assertNotSameSize(Countable|iterable $expected, Countable|iterable $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new HasCount(self::sizeOf($expected))), $message);
    }

    // --- Comparisons -------------------------------------------------------

    public static function assertGreaterThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new GreaterThan($expected), $message);
    }

    public static function assertGreaterThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new LessThan($expected)), $message);
    }

    public static function assertLessThan(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LessThan($expected), $message);
    }

    public static function assertLessThanOrEqual(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new GreaterThan($expected)), $message);
    }

    // --- Iterables ----------------------------------------------------------

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContains($needle), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContains($needle)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsEquals(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContains($needle, strict: false), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertNotContainsEquals(mixed $needle, iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContains($needle, strict: false)), $message);
    }

    /**
     * @param class-string    $className
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyInstancesOf(string $className, iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyInstancesOf($className), $message);
    }

    /**
     * @param class-string    $className
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyInstancesOf(string $className, iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyInstancesOf($className)), $message);
    }

    /**
     * @param array<array-key, mixed>|ArrayAccess<array-key, mixed> $array
     */
    public static function assertArrayHasKey(int|string $key, array|ArrayAccess $array, string $message = ''): void
    {
        self::assertThat($array, new ArrayHasKey($key), $message);
    }

    /**
     * @param array<array-key, mixed>|ArrayAccess<array-key, mixed> $array
     */
    public static function assertArrayNotHasKey(int|string $key, array|ArrayAccess $array, string $message = ''): void
    {
        self::assertThat($array, new LogicalNot(new ArrayHasKey($key)), $message);
    }

    public static function assertIsList(mixed $array, string $message = ''): void
    {
        self::assertThat($array, new IsList(), $message);
    }

    /**
     * The value fits a PHPStan type string, `array{id: positive-int,
     * tags: list<string>}` (D-131). Crucible's own assertion, not the
     * incumbent's: it checks the value at run time, and Crucible's PHPStan
     * extension narrows it to the same type afterwards.
     */
    public static function assertMatchesShape(string $shape, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new MatchesShape($shape), $message);
    }

    // --- Strings -------------------------------------------------------------

    public static function assertStringContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new StringContains($needle), $message);
    }

    public static function assertStringNotContainsString(string $needle, string $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new StringContains($needle)), $message);
    }

    public static function assertStringContainsStringIgnoringCase(string $needle, string $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new StringContains($needle, ignoreCase: true), $message);
    }

    public static function assertStringNotContainsStringIgnoringCase(string $needle, string $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new StringContains($needle, ignoreCase: true)), $message);
    }

    /**
     * @param non-empty-string $prefix
     */
    public static function assertStringStartsWith(string $prefix, string $string, string $message = ''): void
    {
        self::assertThat($string, new StringStartsWith($prefix), $message);
    }

    /**
     * @param non-empty-string $prefix
     */
    public static function assertStringStartsNotWith(string $prefix, string $string, string $message = ''): void
    {
        self::assertThat($string, new LogicalNot(new StringStartsWith($prefix)), $message);
    }

    /**
     * @param non-empty-string $suffix
     */
    public static function assertStringEndsWith(string $suffix, string $string, string $message = ''): void
    {
        self::assertThat($string, new StringEndsWith($suffix), $message);
    }

    /**
     * @param non-empty-string $suffix
     */
    public static function assertStringEndsNotWith(string $suffix, string $string, string $message = ''): void
    {
        self::assertThat($string, new LogicalNot(new StringEndsWith($suffix)), $message);
    }

    /**
     * @param non-empty-string $pattern
     */
    public static function assertMatchesRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        self::assertThat($string, new MatchesRegularExpression($pattern), $message);
    }

    /**
     * @param non-empty-string $pattern
     */
    public static function assertDoesNotMatchRegularExpression(string $pattern, string $string, string $message = ''): void
    {
        self::assertThat($string, new LogicalNot(new MatchesRegularExpression($pattern)), $message);
    }

    public static function assertJson(string $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsJson(), $message);
    }

    // --- Objects & types --------------------------------------------------------

    /**
     * @param class-string $expected
     */
    public static function assertInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsInstanceOf($expected), $message);
    }

    /**
     * @param class-string $expected
     */
    public static function assertNotInstanceOf(string $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsInstanceOf($expected)), $message);
    }

    /**
     * @param non-empty-string $property
     */
    public static function assertObjectHasProperty(string $property, object $object, string $message = ''): void
    {
        self::assertThat($object, new ObjectHasProperty($property), $message);
    }

    /**
     * @param non-empty-string $property
     */
    public static function assertObjectNotHasProperty(string $property, object $object, string $message = ''): void
    {
        self::assertThat($object, new LogicalNot(new ObjectHasProperty($property)), $message);
    }

    // --- Filesystem ----------------------------------------------------------------

    public static function assertFileExists(string $filename, string $message = ''): void
    {
        self::assertThat($filename, new FileExists(), $message);
    }

    public static function assertFileDoesNotExist(string $filename, string $message = ''): void
    {
        self::assertThat($filename, new LogicalNot(new FileExists()), $message);
    }

    public static function assertDirectoryExists(string $directory, string $message = ''): void
    {
        self::assertThat($directory, new DirectoryExists(), $message);
    }

    public static function assertDirectoryDoesNotExist(string $directory, string $message = ''): void
    {
        self::assertThat($directory, new LogicalNot(new DirectoryExists()), $message);
    }

    // --- assertIs*() type family ------------------------------------------------------

    public static function assertIsArray(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Array), $message);
    }

    public static function assertIsNotArray(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Array)), $message);
    }

    public static function assertIsBool(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Bool), $message);
    }

    public static function assertIsNotBool(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Bool)), $message);
    }

    public static function assertIsCallable(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Callable), $message);
    }

    public static function assertIsNotCallable(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Callable)), $message);
    }

    public static function assertIsClosedResource(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::ClosedResource), $message);
    }

    public static function assertIsNotClosedResource(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::ClosedResource)), $message);
    }

    public static function assertIsFloat(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Float), $message);
    }

    public static function assertIsNotFloat(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Float)), $message);
    }

    public static function assertIsInt(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Int), $message);
    }

    public static function assertIsNotInt(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Int)), $message);
    }

    public static function assertIsIterable(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Iterable), $message);
    }

    public static function assertIsNotIterable(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Iterable)), $message);
    }

    public static function assertIsNumeric(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Numeric), $message);
    }

    public static function assertIsNotNumeric(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Numeric)), $message);
    }

    public static function assertIsObject(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Object), $message);
    }

    public static function assertIsNotObject(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Object)), $message);
    }

    public static function assertIsResource(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Resource), $message);
    }

    public static function assertIsNotResource(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Resource)), $message);
    }

    public static function assertIsScalar(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::Scalar), $message);
    }

    public static function assertIsNotScalar(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::Scalar)), $message);
    }

    public static function assertIsString(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsType(ValueType::String), $message);
    }

    public static function assertIsNotString(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsType(ValueType::String)), $message);
    }

    // --- assertContainsOnly*() family ------------------------------------------------

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyArray(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Array), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyBool(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Bool), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyCallable(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Callable), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyFloat(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Float), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyInt(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Int), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyIterable(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Iterable), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyNull(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Null), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyNumeric(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Numeric), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyObject(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Object), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyResource(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Resource), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyClosedResource(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::ClosedResource), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyScalar(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::Scalar), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsOnlyString(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new TraversableContainsOnlyType(ValueType::String), $message);
    }


    // --- assertContainsNotOnly*() family ---------------------------------------------
    //
    // "Not only" is the negation of the whole claim, not a claim about
    // each element: a haystack fails these when every element is of the
    // named type, and passes as soon as one is not — an empty haystack
    // contains only, so it is not "not only".

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyArray(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Array)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyBool(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Bool)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyCallable(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Callable)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyFloat(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Float)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyInt(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Int)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyIterable(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Iterable)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyNull(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Null)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyNumeric(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Numeric)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyObject(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Object)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyResource(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Resource)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyClosedResource(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::ClosedResource)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyScalar(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::Scalar)), $message);
    }

    /**
     * @param iterable<mixed> $haystack
     */
    public static function assertContainsNotOnlyString(iterable $haystack, string $message = ''): void
    {
        self::assertThat($haystack, new LogicalNot(new TraversableContainsOnlyType(ValueType::String)), $message);
    }

    // --- Case- and line-ending-insensitive equality -----------------------------------

    public static function assertEqualsIgnoringCase(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsEqual($expected, ignoreCase: true), $message);
    }

    public static function assertNotEqualsIgnoringCase(mixed $expected, mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new IsEqual($expected, ignoreCase: true)), $message);
    }

    public static function assertStringEqualsStringIgnoringLineEndings(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat($actual, new StringEqualsStringIgnoringLineEndings($expected), $message);
    }

    public static function assertStringContainsStringIgnoringLineEndings(string $needle, string $haystack, string $message = ''): void
    {
        self::assertThat(
            StringEqualsStringIgnoringLineEndings::normalize($haystack),
            new StringContains(StringEqualsStringIgnoringLineEndings::normalize($needle)),
            $message,
        );
    }


    // --- assertArrays*() family -------------------------------------------------------
    //
    // "Are" keeps the keys, "Have*Values" drops them; "Identical" is
    // strict where "Equal" is loose; "IgnoringOrder" relaxes key order
    // for the first pair and makes a multiset of the second.

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysAreEqual(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: false, ignoreKeys: false, ignoreOrder: false), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysAreEqualIgnoringOrder(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: false, ignoreKeys: false, ignoreOrder: true), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysAreIdentical(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: true, ignoreKeys: false, ignoreOrder: false), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysAreIdenticalIgnoringOrder(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: true, ignoreKeys: false, ignoreOrder: true), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysHaveEqualValues(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: false, ignoreKeys: true, ignoreOrder: false), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysHaveEqualValuesIgnoringOrder(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: false, ignoreKeys: true, ignoreOrder: true), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysHaveIdenticalValues(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: true, ignoreKeys: true, ignoreOrder: false), $message);
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     */
    public static function assertArraysHaveIdenticalValuesIgnoringOrder(array $expected, array $actual, string $message = ''): void
    {
        self::assertThat($actual, new ArrayComparison($expected, strict: true, ignoreKeys: true, ignoreOrder: true), $message);
    }

    // --- Key-scoped array comparison ---------------------------------------------------

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     * @param list<array-key>         $keysToBeIgnored
     */
    public static function assertArrayIsEqualToArrayIgnoringListOfKeys(array $expected, array $actual, array $keysToBeIgnored, string $message = ''): void
    {
        self::assertThat(
            self::withoutKeys($actual, $keysToBeIgnored),
            new ArrayComparison(self::withoutKeys($expected, $keysToBeIgnored), strict: false, ignoreKeys: false, ignoreOrder: false),
            $message,
        );
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     * @param list<array-key>         $keysToBeIgnored
     */
    public static function assertArrayIsIdenticalToArrayIgnoringListOfKeys(array $expected, array $actual, array $keysToBeIgnored, string $message = ''): void
    {
        self::assertThat(
            self::withoutKeys($actual, $keysToBeIgnored),
            new ArrayComparison(self::withoutKeys($expected, $keysToBeIgnored), strict: true, ignoreKeys: false, ignoreOrder: false),
            $message,
        );
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     * @param list<array-key>         $keysToBeConsidered
     */
    public static function assertArrayIsEqualToArrayOnlyConsideringListOfKeys(array $expected, array $actual, array $keysToBeConsidered, string $message = ''): void
    {
        self::assertThat(
            self::onlyKeys($actual, $keysToBeConsidered),
            new ArrayComparison(self::onlyKeys($expected, $keysToBeConsidered), strict: false, ignoreKeys: false, ignoreOrder: false),
            $message,
        );
    }

    /**
     * @param array<array-key, mixed> $expected
     * @param array<array-key, mixed> $actual
     * @param list<array-key>         $keysToBeConsidered
     */
    public static function assertArrayIsIdenticalToArrayOnlyConsideringListOfKeys(array $expected, array $actual, array $keysToBeConsidered, string $message = ''): void
    {
        self::assertThat(
            self::onlyKeys($actual, $keysToBeConsidered),
            new ArrayComparison(self::onlyKeys($expected, $keysToBeConsidered), strict: true, ignoreKeys: false, ignoreOrder: false),
            $message,
        );
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<array-key>         $keys
     *
     * @return array<array-key, mixed>
     */
    private static function withoutKeys(array $array, array $keys): array
    {
        foreach ($keys as $key) {
            unset($array[$key]);
        }

        return $array;
    }

    /**
     * @param array<array-key, mixed> $array
     * @param list<array-key>         $keys
     *
     * @return array<array-key, mixed>
     */
    private static function onlyKeys(array $array, array $keys): array
    {
        $kept = [];

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                $kept[$key] = $array[$key];
            }
        }

        return $kept;
    }


    // --- XML document equality ---------------------------------------------------------
    //
    // Canonical form, not string form: attribute order, the declaration,
    // inter-element whitespace and empty-element spelling all wash out,
    // while text-node whitespace does not. Comments are dropped unless
    // the ConsideringComments spelling keeps them. Input that will not
    // parse raises rather than fails — see XmlException.

    public static function assertXmlStringEqualsXmlString(string $expectedXml, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new XmlEqualsXml($expectedXml), $message);
    }

    public static function assertXmlStringNotEqualsXmlString(string $expectedXml, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new LogicalNot(new XmlEqualsXml($expectedXml)), $message);
    }

    public static function assertXmlStringEqualsXmlStringConsideringComments(string $expectedXml, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new XmlEqualsXml($expectedXml, withComments: true), $message);
    }

    public static function assertXmlStringNotEqualsXmlStringConsideringComments(string $expectedXml, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new LogicalNot(new XmlEqualsXml($expectedXml, withComments: true)), $message);
    }

    public static function assertXmlStringEqualsXmlFile(string $expectedFile, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new XmlEqualsXml(self::readFile($expectedFile)), $message);
    }

    public static function assertXmlStringNotEqualsXmlFile(string $expectedFile, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new LogicalNot(new XmlEqualsXml(self::readFile($expectedFile))), $message);
    }

    public static function assertXmlStringEqualsXmlFileConsideringComments(string $expectedFile, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new XmlEqualsXml(self::readFile($expectedFile), withComments: true), $message);
    }

    public static function assertXmlStringNotEqualsXmlFileConsideringComments(string $expectedFile, string $actualXml, string $message = ''): void
    {
        self::assertThat($actualXml, new LogicalNot(new XmlEqualsXml(self::readFile($expectedFile), withComments: true)), $message);
    }

    public static function assertXmlFileEqualsXmlFile(string $expectedFile, string $actualFile, string $message = ''): void
    {
        self::assertThat(self::readFile($actualFile), new XmlEqualsXml(self::readFile($expectedFile)), $message);
    }

    public static function assertXmlFileNotEqualsXmlFile(string $expectedFile, string $actualFile, string $message = ''): void
    {
        self::assertThat(self::readFile($actualFile), new LogicalNot(new XmlEqualsXml(self::readFile($expectedFile))), $message);
    }

    public static function assertXmlFileEqualsXmlFileConsideringComments(string $expectedFile, string $actualFile, string $message = ''): void
    {
        self::assertThat(self::readFile($actualFile), new XmlEqualsXml(self::readFile($expectedFile), withComments: true), $message);
    }

    public static function assertXmlFileNotEqualsXmlFileConsideringComments(string $expectedFile, string $actualFile, string $message = ''): void
    {
        self::assertThat(self::readFile($actualFile), new LogicalNot(new XmlEqualsXml(self::readFile($expectedFile), withComments: true)), $message);
    }

    // --- Whitespace-insensitive equality ----------------------------------------------
    //
    // The file forms read the file as the *expected* side in the
    // String-vs-File spellings, and as both sides in the File-vs-File
    // ones, matching the argument order each name announces.

    public static function assertStringEqualsStringIgnoringWhitespace(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat($actual, new StringEqualsStringIgnoringWhitespace($expected), $message);
    }

    public static function assertStringNotEqualsStringIgnoringWhitespace(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new StringEqualsStringIgnoringWhitespace($expected)), $message);
    }

    public static function assertStringEqualsFileIgnoringWhitespace(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new StringEqualsStringIgnoringWhitespace(self::readFile($expectedFile)), $message);
    }

    public static function assertStringNotEqualsFileIgnoringWhitespace(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new LogicalNot(new StringEqualsStringIgnoringWhitespace(self::readFile($expectedFile))), $message);
    }

    public static function assertFileEqualsFileIgnoringWhitespace(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new StringEqualsStringIgnoringWhitespace(self::readFile($expected)), $message);
    }

    public static function assertFileNotEqualsFileIgnoringWhitespace(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new LogicalNot(new StringEqualsStringIgnoringWhitespace(self::readFile($expected))), $message);
    }

    // --- Float specials -----------------------------------------------------------------

    public static function assertNan(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsNan(), $message);
    }

    public static function assertInfinite(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsInfinite(), $message);
    }

    public static function assertFinite(mixed $actual, string $message = ''): void
    {
        self::assertThat($actual, new IsFinite(), $message);
    }

    // --- Object equality by protocol ------------------------------------------------------

    /**
     * @param non-empty-string $method
     */
    public static function assertObjectEquals(object $expected, object $actual, string $method = 'equals', string $message = ''): void
    {
        self::assertThat($actual, new ObjectEquals($expected, $method), $message);
    }

    /**
     * @param non-empty-string $method
     */
    public static function assertObjectNotEquals(object $expected, object $actual, string $method = 'equals', string $message = ''): void
    {
        self::assertThat($actual, new LogicalNot(new ObjectEquals($expected, $method)), $message);
    }

    // --- Format strings ---------------------------------------------------------------------

    public static function assertStringMatchesFormat(string $format, string $string, string $message = ''): void
    {
        self::assertThat($string, new StringMatchesFormat($format), $message);
    }

    public static function assertStringMatchesFormatFile(string $formatFile, string $string, string $message = ''): void
    {
        self::assertThat($string, new StringMatchesFormat(self::readFile($formatFile)), $message);
    }

    public static function assertFileMatchesFormat(string $format, string $actualFile, string $message = ''): void
    {
        self::assertThat(self::readFile($actualFile), new StringMatchesFormat($format), $message);
    }

    public static function assertFileMatchesFormatFile(string $formatFile, string $actualFile, string $message = ''): void
    {
        self::assertThat(self::readFile($actualFile), new StringMatchesFormat(self::readFile($formatFile)), $message);
    }

    // --- File-content equality -----------------------------------------------------------------

    public static function assertFileEquals(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new IsEqual(self::readFile($expected)), $message);
    }

    public static function assertFileNotEquals(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new LogicalNot(new IsEqual(self::readFile($expected))), $message);
    }

    public static function assertFileEqualsCanonicalizing(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new IsEqual(self::readFile($expected), canonicalize: true), $message);
    }

    public static function assertFileNotEqualsCanonicalizing(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new LogicalNot(new IsEqual(self::readFile($expected), canonicalize: true)), $message);
    }

    public static function assertFileEqualsIgnoringCase(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new IsEqual(self::readFile($expected), ignoreCase: true), $message);
    }

    public static function assertFileNotEqualsIgnoringCase(string $expected, string $actual, string $message = ''): void
    {
        self::assertThat(self::readFile($actual), new LogicalNot(new IsEqual(self::readFile($expected), ignoreCase: true)), $message);
    }

    public static function assertStringEqualsFile(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new IsEqual(self::readFile($expectedFile)), $message);
    }

    public static function assertStringNotEqualsFile(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new LogicalNot(new IsEqual(self::readFile($expectedFile))), $message);
    }

    public static function assertStringEqualsFileCanonicalizing(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new IsEqual(self::readFile($expectedFile), canonicalize: true), $message);
    }

    public static function assertStringNotEqualsFileCanonicalizing(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new LogicalNot(new IsEqual(self::readFile($expectedFile), canonicalize: true)), $message);
    }

    public static function assertStringEqualsFileIgnoringCase(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new IsEqual(self::readFile($expectedFile), ignoreCase: true), $message);
    }

    public static function assertStringNotEqualsFileIgnoringCase(string $expectedFile, string $actualString, string $message = ''): void
    {
        self::assertThat($actualString, new LogicalNot(new IsEqual(self::readFile($expectedFile), ignoreCase: true)), $message);
    }

    // --- JSON comparisons --------------------------------------------------------------------------

    public static function assertJsonStringEqualsJsonString(string $expectedJson, string $actualJson, string $message = ''): void
    {
        self::assertJson($expectedJson, $message);
        self::assertJson($actualJson, $message);
        self::assertThat($actualJson, new JsonMatches($expectedJson), $message);
    }

    public static function assertJsonStringNotEqualsJsonString(string $expectedJson, string $actualJson, string $message = ''): void
    {
        self::assertJson($expectedJson, $message);
        self::assertJson($actualJson, $message);
        self::assertThat($actualJson, new LogicalNot(new JsonMatches($expectedJson)), $message);
    }

    public static function assertJsonStringEqualsJsonFile(string $expectedFile, string $actualJson, string $message = ''): void
    {
        self::assertJsonStringEqualsJsonString(self::readFile($expectedFile), $actualJson, $message);
    }

    public static function assertJsonStringNotEqualsJsonFile(string $expectedFile, string $actualJson, string $message = ''): void
    {
        self::assertJsonStringNotEqualsJsonString(self::readFile($expectedFile), $actualJson, $message);
    }

    public static function assertJsonFileEqualsJsonFile(string $expectedFile, string $actualFile, string $message = ''): void
    {
        self::assertJsonStringEqualsJsonString(self::readFile($expectedFile), self::readFile($actualFile), $message);
    }

    public static function assertJsonFileNotEqualsJsonFile(string $expectedFile, string $actualFile, string $message = ''): void
    {
        self::assertJsonStringNotEqualsJsonString(self::readFile($expectedFile), self::readFile($actualFile), $message);
    }

    // --- Readability & writability ---------------------------------------------------------------------

    public static function assertIsReadable(string $filename, string $message = ''): void
    {
        self::assertThat($filename, new IsReadable(), $message);
    }

    public static function assertIsNotReadable(string $filename, string $message = ''): void
    {
        self::assertThat($filename, new LogicalNot(new IsReadable()), $message);
    }

    public static function assertIsWritable(string $filename, string $message = ''): void
    {
        self::assertThat($filename, new IsWritable(), $message);
    }

    public static function assertIsNotWritable(string $filename, string $message = ''): void
    {
        self::assertThat($filename, new LogicalNot(new IsWritable()), $message);
    }

    public static function assertFileIsReadable(string $file, string $message = ''): void
    {
        self::assertFileExists($file, $message);
        self::assertIsReadable($file, $message);
    }

    public static function assertFileIsNotReadable(string $file, string $message = ''): void
    {
        self::assertFileExists($file, $message);
        self::assertIsNotReadable($file, $message);
    }

    public static function assertFileIsWritable(string $file, string $message = ''): void
    {
        self::assertFileExists($file, $message);
        self::assertIsWritable($file, $message);
    }

    public static function assertFileIsNotWritable(string $file, string $message = ''): void
    {
        self::assertFileExists($file, $message);
        self::assertIsNotWritable($file, $message);
    }

    public static function assertDirectoryIsReadable(string $directory, string $message = ''): void
    {
        self::assertDirectoryExists($directory, $message);
        self::assertIsReadable($directory, $message);
    }

    public static function assertDirectoryIsNotReadable(string $directory, string $message = ''): void
    {
        self::assertDirectoryExists($directory, $message);
        self::assertIsNotReadable($directory, $message);
    }

    public static function assertDirectoryIsWritable(string $directory, string $message = ''): void
    {
        self::assertDirectoryExists($directory, $message);
        self::assertIsWritable($directory, $message);
    }

    public static function assertDirectoryIsNotWritable(string $directory, string $message = ''): void
    {
        self::assertDirectoryExists($directory, $message);
        self::assertIsNotWritable($directory, $message);
    }

    // --- Internals ------------------------------------------------------------------------------------------

    private static function readFile(string $path): string
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new AssertionFailedError(sprintf('Failed asserting that file "%s" exists and is readable.', $path));
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new AssertionFailedError(sprintf('Failed reading file "%s".', $path));
        }

        return $contents;
    }

    /**
     * The snapshot assertion (D-042, Crucible-native): the exported
     * value must equal the stored snapshot; a missing snapshot fails
     * and names --update-snapshots, which is the one way values get
     * recorded. $name pins a stable key when one test snapshots more
     * than once.
     *
     * @param ?non-empty-string $name
     */
    public static function assertMatchesSnapshot(mixed $value, ?string $name = null): void
    {
        Snapshots::match($value, $name);
    }

    /**
     * The inline snapshot assertion (D-076): the expected value lives in
     * this call's own argument, not a `.snap` file. Passing nothing
     * records it under --update-snapshots (the source is rewritten);
     * once recorded, the exported value must equal the stored literal.
     * The call site is captured here — this method's immediate caller is
     * the user's test line.
     */
    public static function assertMatchesInlineSnapshot(mixed $value, ?string $expected = null): void
    {
        $frame = array_first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1));

        Snapshots::matchInline($value, $expected, is_string($frame['file'] ?? null) ? $frame['file'] : '', is_int($frame['line'] ?? null) ? $frame['line'] : 0);
    }

    /**
     * @param Countable|iterable<mixed> $value
     */
    private static function sizeOf(Countable|iterable $value): int
    {
        return is_countable($value) ? count($value) : iterator_count($value);
    }

    /**
     * Constraint builders: the spec's other Assert-trait surface,
     * returning a Constraint instead of asserting one — the shape a
     * mock's ->with() configuration composes matchers from, rather
     * than an immediate pass/fail.
     */
    public static function equalTo(mixed $value, float $delta = 0.0, bool $canonicalize = false, bool $ignoreCase = false): Constraint
    {
        return new IsEqual($value, $delta, $canonicalize, $ignoreCase);
    }

    public static function identicalTo(mixed $value): Constraint
    {
        return new IsIdentical($value);
    }

    /**
     * @param class-string $className
     */
    public static function isInstanceOf(string $className): Constraint
    {
        return new IsInstanceOf($className);
    }

    public static function anything(): Constraint
    {
        return new IsAnything();
    }

    public static function isTrue(): Constraint
    {
        return new IsTrue();
    }

    public static function isFalse(): Constraint
    {
        return new IsFalse();
    }

    public static function isNull(): Constraint
    {
        return new IsNull();
    }

    public static function isEmpty(): Constraint
    {
        return new IsEmpty();
    }

    public static function isJson(): Constraint
    {
        return new IsJson();
    }

    /**
     * @param Closure(mixed): bool $callback
     */
    public static function callback(Closure $callback): Constraint
    {
        return new Callback($callback);
    }

    public static function stringContains(string $needle, bool $ignoreCase = false): Constraint
    {
        return new StringContains($needle, $ignoreCase);
    }

    /**
     * @param non-empty-string $prefix
     */
    public static function stringStartsWith(string $prefix): Constraint
    {
        return new StringStartsWith($prefix);
    }

    /**
     * @param non-empty-string $suffix
     */
    public static function stringEndsWith(string $suffix): Constraint
    {
        return new StringEndsWith($suffix);
    }

    /**
     * @param non-empty-string $pattern
     */
    public static function matchesRegularExpression(string $pattern): Constraint
    {
        return new MatchesRegularExpression($pattern);
    }

    public static function greaterThan(mixed $value): Constraint
    {
        return new GreaterThan($value);
    }

    public static function lessThan(mixed $value): Constraint
    {
        return new LessThan($value);
    }

    public static function countOf(int $count): Constraint
    {
        return new HasCount($count);
    }

    public static function arrayHasKey(int|string $key): Constraint
    {
        return new ArrayHasKey($key);
    }

    /**
     * @param non-empty-string $method
     */
    public static function objectEquals(object $object, string $method = 'equals'): Constraint
    {
        return new ObjectEquals($object, $method);
    }

    public static function logicalNot(Constraint $constraint): Constraint
    {
        return new LogicalNot($constraint);
    }

    /**
     * The Stub half of the spec's Assert-trait surface: a value for
     * ->will(), not a Constraint — the legacy pairing real-world
     * suites still use (`->will($this->throwException($e))`) instead
     * of the modern willThrowException() call this project's own
     * tests use.
     */
    public static function throwException(Throwable $exception): Stub
    {
        return new Stub(static fn(array $args, object $double): never => throw $exception);
    }
}
