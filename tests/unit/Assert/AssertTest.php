<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use ArrayIterator;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Assert\Exporter;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;

/**
 * Behavioral sweep across the implemented assertion families: each
 * asserts the passing case, the failing case (with exception), and —
 * where relevant — the failure structure.
 */
#[CoversClass(Assert::class)]
final class AssertTest extends TestCase
{
    protected function setUp(): void
    {
        Assert::resetAssertionCount();
    }

    public function testIdentityAndCounting(): void
    {
        Assert::assertSame(3, 3);
        Assert::assertNotSame(3, '3');
        Assert::assertSame('a', 'a');

        $this->assertSame(3, Assert::assertionCount());

        $this->expectException(AssertionFailedError::class);
        Assert::assertSame(3, '3');
    }

    public function testSameOnStringsCarriesStructuredDiff(): void
    {
        try {
            Assert::assertSame("line one\nline two", "line one\nline 2");
            $this->fail('Expected an assertion failure.');
        } catch (AssertionFailedError $e) {
            $this->assertStringContainsString('two strings are identical', $e->getMessage());
            self::assertNotNull($e->comparison, 'Expected a structured comparison.');
            $this->assertStringContainsString('-line two', $e->comparison->diff);
            $this->assertStringContainsString('+line 2', $e->comparison->diff);
        }
    }

    public function testEqualityFamilies(): void
    {
        Assert::assertEquals('1', 1);
        Assert::assertNotEquals('1', '01');
        Assert::assertEqualsWithDelta(1.0, 1.04, 0.05);
        Assert::assertNotEqualsWithDelta(1.0, 1.2, 0.05);
        Assert::assertEqualsCanonicalizing([3, 1, 2], [1, 2, 3]);
        Assert::assertNotEqualsCanonicalizing([1, 1], [1, 2]);

        $this->expectException(AssertionFailedError::class);
        Assert::assertEquals([1], [2]);
    }

    public function testBooleanNullAndEmptiness(): void
    {
        Assert::assertTrue(true);
        Assert::assertNotTrue(1);
        Assert::assertFalse(false);
        Assert::assertNotFalse(0);
        Assert::assertNull(null);
        Assert::assertNotNull(0);
        Assert::assertEmpty([]);
        Assert::assertEmpty('');
        Assert::assertNotEmpty([0]);

        $this->expectException(AssertionFailedError::class);
        Assert::assertTrue(1);
    }

    public function testCountsAndSizes(): void
    {
        Assert::assertCount(2, [1, 2]);
        Assert::assertCount(2, new ArrayIterator([1, 2]));
        Assert::assertNotCount(3, [1, 2]);
        Assert::assertSameSize([1, 2], new ArrayIterator(['a', 'b']));
        Assert::assertNotSameSize([1], [1, 2]);

        try {
            Assert::assertCount(3, [1, 2]);
            $this->fail('Expected an assertion failure.');
        } catch (AssertionFailedError $e) {
            $this->assertSame('Failed asserting that actual size 2 matches expected size 3.', $e->getMessage());
        }
    }

    public function testComparisons(): void
    {
        Assert::assertGreaterThan(1, 2);
        Assert::assertGreaterThanOrEqual(2, 2);
        Assert::assertLessThan(2, 1);
        Assert::assertLessThanOrEqual(2, 2);

        $this->expectException(AssertionFailedError::class);
        Assert::assertGreaterThan(2, 2);
    }

    public function testIterableFamilies(): void
    {
        Assert::assertContains(2, [1, 2, 3]);
        Assert::assertNotContains('2', [1, 2, 3]);
        Assert::assertContainsEquals('2', [1, 2, 3]);
        Assert::assertContains('b', new ArrayIterator(['a', 'b']));
        Assert::assertContainsOnlyInt([1, 2, 3]);
        Assert::assertContainsOnlyString(['a', 'b']);
        Assert::assertContainsOnlyInstancesOf(stdClass::class, [new stdClass(), new stdClass()]);
        Assert::assertArrayHasKey('k', ['k' => null]);
        Assert::assertArrayNotHasKey('x', ['k' => null]);
        Assert::assertIsList([1, 2]);

        $this->expectException(AssertionFailedError::class);
        Assert::assertContainsOnlyInt([1, 'two']);
    }

    public function testStringFamilies(): void
    {
        Assert::assertStringContainsString('cib', 'crucible');
        Assert::assertStringNotContainsString('CIB', 'crucible');
        Assert::assertStringContainsStringIgnoringCase('CIB', 'crucible');
        Assert::assertStringStartsWith('cru', 'crucible');
        Assert::assertStringStartsNotWith('ble', 'crucible');
        Assert::assertStringEndsWith('ble', 'crucible');
        Assert::assertStringEndsNotWith('cru', 'crucible');
        Assert::assertMatchesRegularExpression('/^cru/', 'crucible');
        Assert::assertDoesNotMatchRegularExpression('/^forge/', 'crucible');
        Assert::assertJson('{"a":1}');

        $this->expectException(AssertionFailedError::class);
        Assert::assertJson('{nope');
    }

    public function testObjectAndTypeFamilies(): void
    {
        Assert::assertInstanceOf(stdClass::class, new stdClass());
        Assert::assertNotInstanceOf(Exporter::class, new stdClass());

        $object    = new stdClass();
        $object->p = 1;
        Assert::assertObjectHasProperty('p', $object);
        Assert::assertObjectNotHasProperty('q', $object);

        Assert::assertIsArray([]);
        Assert::assertIsNotArray('a');
        Assert::assertIsBool(true);
        Assert::assertIsCallable(static fn(): int => 1);
        Assert::assertIsFloat(1.5);
        Assert::assertIsInt(1);
        Assert::assertIsIterable([]);
        Assert::assertIsNumeric('1.5');
        Assert::assertIsObject(new stdClass());
        Assert::assertIsScalar('s');
        Assert::assertIsString('s');
        Assert::assertIsNotString(1);

        $this->expectException(AssertionFailedError::class);
        Assert::assertIsInt('1');
    }

    public function testFilesystemFamilies(): void
    {
        Assert::assertFileExists(__FILE__);
        Assert::assertFileDoesNotExist(__FILE__ . '.nope');
        Assert::assertDirectoryExists(__DIR__);
        Assert::assertDirectoryDoesNotExist(__DIR__ . '/nope');

        $this->expectException(AssertionFailedError::class);
        Assert::assertFileExists(__FILE__ . '.nope');
    }

    public function testFailThrowsAndPrependsCustomMessages(): void
    {
        try {
            Assert::assertTrue(false, 'the flag must be enabled');
            $this->fail('Expected an assertion failure.');
        } catch (AssertionFailedError $e) {
            $this->assertSame(
                "the flag must be enabled\nFailed asserting that false is true.",
                $e->getMessage(),
            );
        }

        $this->expectException(AssertionFailedError::class);
        Assert::fail('stop here');
    }
}
