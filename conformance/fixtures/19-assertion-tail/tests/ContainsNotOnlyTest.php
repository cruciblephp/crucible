<?php

declare(strict_types=1);

namespace CrucibleConformance\AssertionTail;

use PHPUnit\Framework\TestCase;
use stdClass;

use function fclose;
use function fopen;

/**
 * The assertContainsNotOnly*() family, both directions, plus the two
 * edges that decide what "not only" means: an empty haystack, and a
 * haystack whose elements are all of the named type.
 */
final class ContainsNotOnlyTest extends TestCase
{
    public function testArrayHolds(): void
    {
        $this->assertContainsNotOnlyArray([[1], 'not an array']);
    }

    public function testArrayFails(): void
    {
        $this->assertContainsNotOnlyArray([[1], [2]]);
    }

    public function testBoolHolds(): void
    {
        $this->assertContainsNotOnlyBool([true, 1]);
    }

    public function testBoolFails(): void
    {
        $this->assertContainsNotOnlyBool([true, false]);
    }

    public function testCallableHolds(): void
    {
        $this->assertContainsNotOnlyCallable(['strlen', 42]);
    }

    public function testCallableFails(): void
    {
        $this->assertContainsNotOnlyCallable(['strlen', 'strtolower']);
    }

    public function testFloatHolds(): void
    {
        $this->assertContainsNotOnlyFloat([1.5, 2]);
    }

    public function testFloatFails(): void
    {
        $this->assertContainsNotOnlyFloat([1.5, 2.5]);
    }

    public function testIntHolds(): void
    {
        $this->assertContainsNotOnlyInt([1, 'two']);
    }

    public function testIntFails(): void
    {
        $this->assertContainsNotOnlyInt([1, 2, 3]);
    }

    public function testIntOnEmptyHaystack(): void
    {
        $this->assertContainsNotOnlyInt([]);
    }

    public function testIterableHolds(): void
    {
        $this->assertContainsNotOnlyIterable([[1], 'no']);
    }

    public function testIterableFails(): void
    {
        $this->assertContainsNotOnlyIterable([[1], [2]]);
    }

    public function testNullHolds(): void
    {
        $this->assertContainsNotOnlyNull([null, 0]);
    }

    public function testNullFails(): void
    {
        $this->assertContainsNotOnlyNull([null, null]);
    }

    public function testNumericHolds(): void
    {
        $this->assertContainsNotOnlyNumeric([1, 'x']);
    }

    public function testNumericFails(): void
    {
        $this->assertContainsNotOnlyNumeric([1, '2', 3.0]);
    }

    public function testObjectHolds(): void
    {
        $this->assertContainsNotOnlyObject([new stdClass(), 'no']);
    }

    public function testObjectFails(): void
    {
        $this->assertContainsNotOnlyObject([new stdClass(), new stdClass()]);
    }

    public function testScalarHolds(): void
    {
        $this->assertContainsNotOnlyScalar([1, []]);
    }

    public function testScalarFails(): void
    {
        $this->assertContainsNotOnlyScalar([1, 'a', true]);
    }

    public function testStringHolds(): void
    {
        $this->assertContainsNotOnlyString(['a', 1]);
    }

    public function testStringFails(): void
    {
        $this->assertContainsNotOnlyString(['a', 'b']);
    }

    public function testResourceHolds(): void
    {
        $handle = fopen('php://memory', 'rb');
        $this->assertContainsNotOnlyResource([$handle, 'not a resource']);
        fclose($handle);
    }

    public function testResourceFails(): void
    {
        $first  = fopen('php://memory', 'rb');
        $second = fopen('php://memory', 'rb');
        $this->assertContainsNotOnlyResource([$first, $second]);
        fclose($first);
        fclose($second);
    }

    public function testClosedResourceHolds(): void
    {
        $open   = fopen('php://memory', 'rb');
        $closed = fopen('php://memory', 'rb');
        fclose($closed);
        $this->assertContainsNotOnlyClosedResource([$closed, $open]);
        fclose($open);
    }

    public function testClosedResourceFails(): void
    {
        $first  = fopen('php://memory', 'rb');
        $second = fopen('php://memory', 'rb');
        fclose($first);
        fclose($second);
        $this->assertContainsNotOnlyClosedResource([$first, $second]);
    }

    public function testInstancesOfHolds(): void
    {
        $this->assertContainsNotOnlyInstancesOf(stdClass::class, [new stdClass(), $this]);
    }

    public function testInstancesOfFails(): void
    {
        $this->assertContainsNotOnlyInstancesOf(stdClass::class, [new stdClass(), new stdClass()]);
    }

    public function testInstancesOfOnEmptyHaystack(): void
    {
        $this->assertContainsNotOnlyInstancesOf(stdClass::class, []);
    }
}
