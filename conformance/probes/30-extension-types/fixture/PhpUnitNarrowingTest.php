<?php

declare(strict_types=1);

namespace CrucibleProbe\Types;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

use function PHPStan\dumpType;

/*
 * What each narrowing assertion tells the analyser, in the three spellings
 * a suite uses. One dumpType() per line, labelled by the comment at its end:
 * the label is the record's key, so editing the file never reshuffles it.
 */
final class PhpUnitNarrowingTest extends TestCase
{
    public function testIsArrayThis(): void
    {
        $v = mixedValue();
        $this->assertIsArray($v);
        dumpType($v); // phpunit.isArray.this
    }

    public function testIsArraySelf(): void
    {
        $v = mixedValue();
        self::assertIsArray($v);
        dumpType($v); // phpunit.isArray.self
    }

    public function testIsArrayStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsArray($v);
        dumpType($v); // phpunit.isArray.static
    }

    public function testIsBoolThis(): void
    {
        $v = mixedValue();
        $this->assertIsBool($v);
        dumpType($v); // phpunit.isBool.this
    }

    public function testIsBoolSelf(): void
    {
        $v = mixedValue();
        self::assertIsBool($v);
        dumpType($v); // phpunit.isBool.self
    }

    public function testIsBoolStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsBool($v);
        dumpType($v); // phpunit.isBool.static
    }

    public function testIsCallableThis(): void
    {
        $v = mixedValue();
        $this->assertIsCallable($v);
        dumpType($v); // phpunit.isCallable.this
    }

    public function testIsCallableSelf(): void
    {
        $v = mixedValue();
        self::assertIsCallable($v);
        dumpType($v); // phpunit.isCallable.self
    }

    public function testIsCallableStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsCallable($v);
        dumpType($v); // phpunit.isCallable.static
    }

    public function testIsFloatThis(): void
    {
        $v = mixedValue();
        $this->assertIsFloat($v);
        dumpType($v); // phpunit.isFloat.this
    }

    public function testIsFloatSelf(): void
    {
        $v = mixedValue();
        self::assertIsFloat($v);
        dumpType($v); // phpunit.isFloat.self
    }

    public function testIsFloatStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsFloat($v);
        dumpType($v); // phpunit.isFloat.static
    }

    public function testIsIntThis(): void
    {
        $v = mixedValue();
        $this->assertIsInt($v);
        dumpType($v); // phpunit.isInt.this
    }

    public function testIsIntSelf(): void
    {
        $v = mixedValue();
        self::assertIsInt($v);
        dumpType($v); // phpunit.isInt.self
    }

    public function testIsIntStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsInt($v);
        dumpType($v); // phpunit.isInt.static
    }

    public function testIsIterableThis(): void
    {
        $v = mixedValue();
        $this->assertIsIterable($v);
        dumpType($v); // phpunit.isIterable.this
    }

    public function testIsIterableSelf(): void
    {
        $v = mixedValue();
        self::assertIsIterable($v);
        dumpType($v); // phpunit.isIterable.self
    }

    public function testIsIterableStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsIterable($v);
        dumpType($v); // phpunit.isIterable.static
    }

    public function testIsNumericThis(): void
    {
        $v = mixedValue();
        $this->assertIsNumeric($v);
        dumpType($v); // phpunit.isNumeric.this
    }

    public function testIsNumericSelf(): void
    {
        $v = mixedValue();
        self::assertIsNumeric($v);
        dumpType($v); // phpunit.isNumeric.self
    }

    public function testIsNumericStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsNumeric($v);
        dumpType($v); // phpunit.isNumeric.static
    }

    public function testIsObjectThis(): void
    {
        $v = mixedValue();
        $this->assertIsObject($v);
        dumpType($v); // phpunit.isObject.this
    }

    public function testIsObjectSelf(): void
    {
        $v = mixedValue();
        self::assertIsObject($v);
        dumpType($v); // phpunit.isObject.self
    }

    public function testIsObjectStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsObject($v);
        dumpType($v); // phpunit.isObject.static
    }

    public function testIsResourceThis(): void
    {
        $v = mixedValue();
        $this->assertIsResource($v);
        dumpType($v); // phpunit.isResource.this
    }

    public function testIsResourceSelf(): void
    {
        $v = mixedValue();
        self::assertIsResource($v);
        dumpType($v); // phpunit.isResource.self
    }

    public function testIsResourceStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsResource($v);
        dumpType($v); // phpunit.isResource.static
    }

    public function testIsScalarThis(): void
    {
        $v = mixedValue();
        $this->assertIsScalar($v);
        dumpType($v); // phpunit.isScalar.this
    }

    public function testIsScalarSelf(): void
    {
        $v = mixedValue();
        self::assertIsScalar($v);
        dumpType($v); // phpunit.isScalar.self
    }

    public function testIsScalarStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsScalar($v);
        dumpType($v); // phpunit.isScalar.static
    }

    public function testIsStringThis(): void
    {
        $v = mixedValue();
        $this->assertIsString($v);
        dumpType($v); // phpunit.isString.this
    }

    public function testIsStringSelf(): void
    {
        $v = mixedValue();
        self::assertIsString($v);
        dumpType($v); // phpunit.isString.self
    }

    public function testIsStringStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsString($v);
        dumpType($v); // phpunit.isString.static
    }

    public function testIsListThis(): void
    {
        $v = payload();
        $this->assertIsList($v);
        dumpType($v); // phpunit.isList.this
    }

    public function testIsListSelf(): void
    {
        $v = payload();
        self::assertIsList($v);
        dumpType($v); // phpunit.isList.self
    }

    public function testIsListStatic(): void
    {
        $v = payload();
        Assert::assertIsList($v);
        dumpType($v); // phpunit.isList.static
    }

    public function testIsNotStringThis(): void
    {
        $v = intOrString();
        $this->assertIsNotString($v);
        dumpType($v); // phpunit.isNotString.this
    }

    public function testIsNotStringSelf(): void
    {
        $v = intOrString();
        self::assertIsNotString($v);
        dumpType($v); // phpunit.isNotString.self
    }

    public function testIsNotStringStatic(): void
    {
        $v = intOrString();
        Assert::assertIsNotString($v);
        dumpType($v); // phpunit.isNotString.static
    }

    public function testIsNotIntThis(): void
    {
        $v = intOrString();
        $this->assertIsNotInt($v);
        dumpType($v); // phpunit.isNotInt.this
    }

    public function testIsNotIntSelf(): void
    {
        $v = intOrString();
        self::assertIsNotInt($v);
        dumpType($v); // phpunit.isNotInt.self
    }

    public function testIsNotIntStatic(): void
    {
        $v = intOrString();
        Assert::assertIsNotInt($v);
        dumpType($v); // phpunit.isNotInt.static
    }

    public function testIsNotArrayThis(): void
    {
        $v = mixedValue();
        $this->assertIsNotArray($v);
        dumpType($v); // phpunit.isNotArray.this
    }

    public function testIsNotArraySelf(): void
    {
        $v = mixedValue();
        self::assertIsNotArray($v);
        dumpType($v); // phpunit.isNotArray.self
    }

    public function testIsNotArrayStatic(): void
    {
        $v = mixedValue();
        Assert::assertIsNotArray($v);
        dumpType($v); // phpunit.isNotArray.static
    }

    public function testNullThis(): void
    {
        $v = maybeString();
        $this->assertNull($v);
        dumpType($v); // phpunit.null.this
    }

    public function testNullSelf(): void
    {
        $v = maybeString();
        self::assertNull($v);
        dumpType($v); // phpunit.null.self
    }

    public function testNullStatic(): void
    {
        $v = maybeString();
        Assert::assertNull($v);
        dumpType($v); // phpunit.null.static
    }

    public function testNotNullThis(): void
    {
        $v = maybeString();
        $this->assertNotNull($v);
        dumpType($v); // phpunit.notNull.this
    }

    public function testNotNullSelf(): void
    {
        $v = maybeString();
        self::assertNotNull($v);
        dumpType($v); // phpunit.notNull.self
    }

    public function testNotNullStatic(): void
    {
        $v = maybeString();
        Assert::assertNotNull($v);
        dumpType($v); // phpunit.notNull.static
    }

    public function testTrueThis(): void
    {
        $v = maybeBool();
        $this->assertTrue($v);
        dumpType($v); // phpunit.true.this
    }

    public function testTrueSelf(): void
    {
        $v = maybeBool();
        self::assertTrue($v);
        dumpType($v); // phpunit.true.self
    }

    public function testTrueStatic(): void
    {
        $v = maybeBool();
        Assert::assertTrue($v);
        dumpType($v); // phpunit.true.static
    }

    public function testNotTrueThis(): void
    {
        $v = maybeBool();
        $this->assertNotTrue($v);
        dumpType($v); // phpunit.notTrue.this
    }

    public function testNotTrueSelf(): void
    {
        $v = maybeBool();
        self::assertNotTrue($v);
        dumpType($v); // phpunit.notTrue.self
    }

    public function testNotTrueStatic(): void
    {
        $v = maybeBool();
        Assert::assertNotTrue($v);
        dumpType($v); // phpunit.notTrue.static
    }

    public function testFalseThis(): void
    {
        $v = maybeBool();
        $this->assertFalse($v);
        dumpType($v); // phpunit.false.this
    }

    public function testFalseSelf(): void
    {
        $v = maybeBool();
        self::assertFalse($v);
        dumpType($v); // phpunit.false.self
    }

    public function testFalseStatic(): void
    {
        $v = maybeBool();
        Assert::assertFalse($v);
        dumpType($v); // phpunit.false.static
    }

    public function testNotFalseThis(): void
    {
        $v = maybeBool();
        $this->assertNotFalse($v);
        dumpType($v); // phpunit.notFalse.this
    }

    public function testNotFalseSelf(): void
    {
        $v = maybeBool();
        self::assertNotFalse($v);
        dumpType($v); // phpunit.notFalse.self
    }

    public function testNotFalseStatic(): void
    {
        $v = maybeBool();
        Assert::assertNotFalse($v);
        dumpType($v); // phpunit.notFalse.static
    }

    public function testEmptyThis(): void
    {
        $v = maybeString();
        $this->assertEmpty($v);
        dumpType($v); // phpunit.empty.this
    }

    public function testEmptySelf(): void
    {
        $v = maybeString();
        self::assertEmpty($v);
        dumpType($v); // phpunit.empty.self
    }

    public function testEmptyStatic(): void
    {
        $v = maybeString();
        Assert::assertEmpty($v);
        dumpType($v); // phpunit.empty.static
    }

    public function testNotEmptyThis(): void
    {
        $v = maybeString();
        $this->assertNotEmpty($v);
        dumpType($v); // phpunit.notEmpty.this
    }

    public function testNotEmptySelf(): void
    {
        $v = maybeString();
        self::assertNotEmpty($v);
        dumpType($v); // phpunit.notEmpty.self
    }

    public function testNotEmptyStatic(): void
    {
        $v = maybeString();
        Assert::assertNotEmpty($v);
        dumpType($v); // phpunit.notEmpty.static
    }

    public function testSameThis(): void
    {
        $v = intOrString();
        $this->assertSame(5, $v);
        dumpType($v); // phpunit.same.this
    }

    public function testSameSelf(): void
    {
        $v = intOrString();
        self::assertSame(5, $v);
        dumpType($v); // phpunit.same.self
    }

    public function testSameStatic(): void
    {
        $v = intOrString();
        Assert::assertSame(5, $v);
        dumpType($v); // phpunit.same.static
    }

    public function testNotSameThis(): void
    {
        $v = maybeString();
        $this->assertNotSame(null, $v);
        dumpType($v); // phpunit.notSame.this
    }

    public function testNotSameSelf(): void
    {
        $v = maybeString();
        self::assertNotSame(null, $v);
        dumpType($v); // phpunit.notSame.self
    }

    public function testNotSameStatic(): void
    {
        $v = maybeString();
        Assert::assertNotSame(null, $v);
        dumpType($v); // phpunit.notSame.static
    }

    public function testInstanceOfThis(): void
    {
        $v = object();
        $this->assertInstanceOf(Widget::class, $v);
        dumpType($v); // phpunit.instanceOf.this
    }

    public function testInstanceOfSelf(): void
    {
        $v = object();
        self::assertInstanceOf(Widget::class, $v);
        dumpType($v); // phpunit.instanceOf.self
    }

    public function testInstanceOfStatic(): void
    {
        $v = object();
        Assert::assertInstanceOf(Widget::class, $v);
        dumpType($v); // phpunit.instanceOf.static
    }

    public function testNotInstanceOfThis(): void
    {
        $v = maybeWidget();
        $this->assertNotInstanceOf(Widget::class, $v);
        dumpType($v); // phpunit.notInstanceOf.this
    }

    public function testNotInstanceOfSelf(): void
    {
        $v = maybeWidget();
        self::assertNotInstanceOf(Widget::class, $v);
        dumpType($v); // phpunit.notInstanceOf.self
    }

    public function testNotInstanceOfStatic(): void
    {
        $v = maybeWidget();
        Assert::assertNotInstanceOf(Widget::class, $v);
        dumpType($v); // phpunit.notInstanceOf.static
    }

    public function testCountThis(): void
    {
        $v = payload();
        $this->assertCount(2, $v);
        dumpType($v); // phpunit.count.this
    }

    public function testCountSelf(): void
    {
        $v = payload();
        self::assertCount(2, $v);
        dumpType($v); // phpunit.count.self
    }

    public function testCountStatic(): void
    {
        $v = payload();
        Assert::assertCount(2, $v);
        dumpType($v); // phpunit.count.static
    }

    public function testNotCountThis(): void
    {
        $v = payload();
        $this->assertNotCount(0, $v);
        dumpType($v); // phpunit.notCount.this
    }

    public function testNotCountSelf(): void
    {
        $v = payload();
        self::assertNotCount(0, $v);
        dumpType($v); // phpunit.notCount.self
    }

    public function testNotCountStatic(): void
    {
        $v = payload();
        Assert::assertNotCount(0, $v);
        dumpType($v); // phpunit.notCount.static
    }

    public function testArrayHasKeyThis(): void
    {
        $v = payload();
        $this->assertArrayHasKey('id', $v);
        dumpType($v); // phpunit.arrayHasKey.this
    }

    public function testArrayHasKeySelf(): void
    {
        $v = payload();
        self::assertArrayHasKey('id', $v);
        dumpType($v); // phpunit.arrayHasKey.self
    }

    public function testArrayHasKeyStatic(): void
    {
        $v = payload();
        Assert::assertArrayHasKey('id', $v);
        dumpType($v); // phpunit.arrayHasKey.static
    }

    public function testArrayNotHasKeyThis(): void
    {
        $v = payload();
        $this->assertArrayNotHasKey('id', $v);
        dumpType($v); // phpunit.arrayNotHasKey.this
    }

    public function testArrayNotHasKeySelf(): void
    {
        $v = payload();
        self::assertArrayNotHasKey('id', $v);
        dumpType($v); // phpunit.arrayNotHasKey.self
    }

    public function testArrayNotHasKeyStatic(): void
    {
        $v = payload();
        Assert::assertArrayNotHasKey('id', $v);
        dumpType($v); // phpunit.arrayNotHasKey.static
    }

    public function testObjectHasPropertyThis(): void
    {
        $v = object();
        $this->assertObjectHasProperty('id', $v);
        dumpType($v); // phpunit.objectHasProperty.this
    }

    public function testObjectHasPropertySelf(): void
    {
        $v = object();
        self::assertObjectHasProperty('id', $v);
        dumpType($v); // phpunit.objectHasProperty.self
    }

    public function testObjectHasPropertyStatic(): void
    {
        $v = object();
        Assert::assertObjectHasProperty('id', $v);
        dumpType($v); // phpunit.objectHasProperty.static
    }

    public function testObjectNotHasPropertyThis(): void
    {
        $v = object();
        $this->assertObjectNotHasProperty('id', $v);
        dumpType($v); // phpunit.objectNotHasProperty.this
    }

    public function testObjectNotHasPropertySelf(): void
    {
        $v = object();
        self::assertObjectNotHasProperty('id', $v);
        dumpType($v); // phpunit.objectNotHasProperty.self
    }

    public function testObjectNotHasPropertyStatic(): void
    {
        $v = object();
        Assert::assertObjectNotHasProperty('id', $v);
        dumpType($v); // phpunit.objectNotHasProperty.static
    }

    public function testContainsOnlyInstancesOfThis(): void
    {
        $v = items();
        $this->assertContainsOnlyInstancesOf(Widget::class, $v);
        dumpType($v); // phpunit.containsOnlyInstancesOf.this
    }

    public function testContainsOnlyInstancesOfSelf(): void
    {
        $v = items();
        self::assertContainsOnlyInstancesOf(Widget::class, $v);
        dumpType($v); // phpunit.containsOnlyInstancesOf.self
    }

    public function testContainsOnlyInstancesOfStatic(): void
    {
        $v = items();
        Assert::assertContainsOnlyInstancesOf(Widget::class, $v);
        dumpType($v); // phpunit.containsOnlyInstancesOf.static
    }
}
