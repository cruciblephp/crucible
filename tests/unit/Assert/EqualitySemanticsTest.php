<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use DateTimeImmutable;
use LucianoPereira\Crucible\Assert\Constraint\IsEqual;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use stdClass;

use const NAN;

#[CoversClass(IsEqual::class)]
final class EqualitySemanticsTest extends TestCase
{
    public function testNumericJugglingBetweenNumberAndNumericString(): void
    {
        $this->assertTrue((new IsEqual(1))->matches('1'));
        $this->assertTrue((new IsEqual('1.0'))->matches(1));
        $this->assertTrue((new IsEqual(1.0))->matches(1));
    }

    public function testTwoStringsNeverJuggleNumerically(): void
    {
        $this->assertFalse((new IsEqual('1'))->matches('01'));
        $this->assertTrue((new IsEqual('1'))->matches('1'));
    }

    public function testNanIsNeverEqual(): void
    {
        $this->assertFalse((new IsEqual(NAN))->matches(NAN));
    }

    public function testDeltaAppliesToNumbersAndRecursesIntoArrays(): void
    {
        $this->assertTrue((new IsEqual(1.0, delta: 0.1))->matches(1.05));
        $this->assertFalse((new IsEqual(1.0, delta: 0.01))->matches(1.05));
        $this->assertTrue((new IsEqual(['x' => 1.0], delta: 0.1))->matches(['x' => 1.05]));
    }

    public function testArraysCompareByKeysOrderInsensitive(): void
    {
        $this->assertTrue((new IsEqual(['a' => 1, 'b' => 2]))->matches(['b' => 2, 'a' => 1]));
        $this->assertFalse((new IsEqual([1, 2]))->matches([2, 1]));
    }

    public function testCanonicalizeComparesListsAsMultisets(): void
    {
        $this->assertTrue((new IsEqual([1, 2, 2], canonicalize: true))->matches([2, 1, 2]));
        $this->assertFalse((new IsEqual([1, 2, 2], canonicalize: true))->matches([1, 1, 2]));
    }

    public function testDateTimesCompareByInstant(): void
    {
        $utc    = new DateTimeImmutable('2026-07-14T12:00:00+00:00');
        $offset = new DateTimeImmutable('2026-07-14T14:00:00+02:00');

        $this->assertTrue((new IsEqual($utc))->matches($offset));
    }

    public function testObjectsCompareByClassAndPropertiesRecursively(): void
    {
        $a    = new stdClass();
        $a->x = ['k' => 1.0];
        $b    = new stdClass();
        $b->x = ['k' => 1];

        $this->assertTrue((new IsEqual($a))->matches($b));

        $c    = new stdClass();
        $c->x = ['k' => 2];

        $this->assertFalse((new IsEqual($a))->matches($c));
    }

    public function testCyclicObjectGraphsDoNotRecurseForever(): void
    {
        $a       = new stdClass();
        $a->self = $a;
        $b       = new stdClass();
        $b->self = $b;

        $this->assertTrue((new IsEqual($a))->matches($b));
    }

    /**
     * Real PHPUnit's own ScalarComparator explicitly allows this
     * ("allow comparison between strings and objects featuring
     * __toString()") — confirmed against a real spatie/laravel-data
     * case: a validation rule attribute object equals its string form.
     */
    public function testAStringableObjectEqualsItsStringForm(): void
    {
        $stringable = new class {
            public function __toString(): string
            {
                return 'required_if:bla';
            }
        };

        $this->assertTrue((new IsEqual('required_if:bla'))->matches($stringable));
        $this->assertTrue((new IsEqual($stringable))->matches('required_if:bla'));
        $this->assertFalse((new IsEqual('something else'))->matches($stringable));
    }

    public function testANonStringableObjectIsNeverEqualToAString(): void
    {
        $this->assertFalse((new IsEqual('anything'))->matches(new stdClass()));
        $this->assertFalse((new IsEqual(new stdClass()))->matches('anything'));
    }
}
