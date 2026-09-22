<?php

declare(strict_types=1);

namespace CrucibleConformance\AssertionTail;

use PHPUnit\Framework\TestCase;

/**
 * The assertArrays*() family. The cases are chosen to separate the three
 * axes that the eight names encode — strict vs loose, keys kept vs
 * dropped, order significant vs not — including the one that reads
 * backwards: "ignoring order" keeps the pairing, so two lists holding
 * the same values in a different order are still not equal.
 */
final class ArraysTest extends TestCase
{
    public function testAreEqualIgnoresKeyOrder(): void
    {
        $this->assertArraysAreEqual(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]);
    }

    public function testAreEqualIsLooseAboutValues(): void
    {
        $this->assertArraysAreEqual([1], ['1']);
    }

    public function testAreEqualStillPairsKeyToValue(): void
    {
        $this->assertArraysAreEqual([1, 2], [2, 1]);
    }

    public function testAreEqualFailsOnDifferentKeys(): void
    {
        $this->assertArraysAreEqual(['a' => 1], ['b' => 1]);
    }

    public function testAreIdenticalIsStrict(): void
    {
        $this->assertArraysAreIdentical([1], ['1']);
    }

    public function testAreIdenticalMindsKeyOrder(): void
    {
        $this->assertArraysAreIdentical(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]);
    }

    public function testAreIdenticalHolds(): void
    {
        $this->assertArraysAreIdentical(['a' => 1], ['a' => 1]);
    }

    public function testAreEqualIgnoringOrderIgnoresKeyOrder(): void
    {
        $this->assertArraysAreEqualIgnoringOrder(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]);
    }

    public function testAreEqualIgnoringOrderStillPairs(): void
    {
        $this->assertArraysAreEqualIgnoringOrder([1, 2], [2, 1]);
    }

    public function testAreIdenticalIgnoringOrderIgnoresKeyOrder(): void
    {
        $this->assertArraysAreIdenticalIgnoringOrder(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]);
    }

    public function testAreIdenticalIgnoringOrderIsStillStrict(): void
    {
        $this->assertArraysAreIdenticalIgnoringOrder(['a' => 1], ['a' => '1']);
    }

    public function testHaveEqualValuesDropsKeys(): void
    {
        $this->assertArraysHaveEqualValues(['a' => 1], ['b' => 1]);
    }

    public function testHaveEqualValuesIsLoose(): void
    {
        $this->assertArraysHaveEqualValues(['a' => 1], ['a' => '1']);
    }

    public function testHaveEqualValuesMindsValueOrder(): void
    {
        $this->assertArraysHaveEqualValues([1, 2], [2, 1]);
    }

    public function testHaveEqualValuesMindsCount(): void
    {
        $this->assertArraysHaveEqualValues([1, 2], [1, 2, 2]);
    }

    public function testHaveIdenticalValuesDropsKeys(): void
    {
        $this->assertArraysHaveIdenticalValues(['a' => 1], ['b' => 1]);
    }

    public function testHaveIdenticalValuesIsStrict(): void
    {
        $this->assertArraysHaveIdenticalValues(['a' => 1], ['a' => '1']);
    }

    public function testHaveEqualValuesIgnoringOrderHolds(): void
    {
        $this->assertArraysHaveEqualValuesIgnoringOrder([1, 2], [2, 1]);
    }

    public function testHaveIdenticalValuesIgnoringOrderHolds(): void
    {
        $this->assertArraysHaveIdenticalValuesIgnoringOrder([1, 1, 2], [2, 1, 1]);
    }

    public function testHaveIdenticalValuesIgnoringOrderKeepsDuplicateCounts(): void
    {
        $this->assertArraysHaveIdenticalValuesIgnoringOrder([1, 1, 2], [1, 2, 2]);
    }

    public function testIgnoringListOfKeysSkipsTheIgnoredOne(): void
    {
        $this->assertArrayIsEqualToArrayIgnoringListOfKeys(['a' => 1, 'b' => 9], ['a' => 1, 'b' => 8], ['b']);
    }

    public function testIgnoringListOfKeysStillJudgesTheRest(): void
    {
        $this->assertArrayIsEqualToArrayIgnoringListOfKeys(['a' => 1, 'b' => 9], ['a' => 2, 'b' => 9], ['b']);
    }

    public function testOnlyConsideringListOfKeysHolds(): void
    {
        $this->assertArrayIsEqualToArrayOnlyConsideringListOfKeys(['a' => 1, 'b' => 9], ['a' => 1, 'b' => 8], ['a']);
    }

    public function testOnlyConsideringListOfKeysFails(): void
    {
        $this->assertArrayIsEqualToArrayOnlyConsideringListOfKeys(['a' => 1, 'b' => 9], ['a' => 2, 'b' => 9], ['a']);
    }

    public function testIdenticalIgnoringListOfKeysIsStrict(): void
    {
        $this->assertArrayIsIdenticalToArrayIgnoringListOfKeys(['a' => 1, 'b' => 9], ['a' => '1', 'b' => 8], ['b']);
    }

    public function testIdenticalIgnoringListOfKeysHolds(): void
    {
        $this->assertArrayIsIdenticalToArrayIgnoringListOfKeys(['a' => 1, 'b' => 9], ['a' => 1, 'b' => 8], ['b']);
    }

    public function testIdenticalOnlyConsideringListOfKeysIsStrict(): void
    {
        $this->assertArrayIsIdenticalToArrayOnlyConsideringListOfKeys(['a' => 1, 'b' => 9], ['a' => '1', 'b' => 9], ['a']);
    }

    public function testIdenticalOnlyConsideringListOfKeysHolds(): void
    {
        $this->assertArrayIsIdenticalToArrayOnlyConsideringListOfKeys(['a' => 1, 'b' => 9], ['a' => 1, 'b' => 8], ['a']);
    }
}
