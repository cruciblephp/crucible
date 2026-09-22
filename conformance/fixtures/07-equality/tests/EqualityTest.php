<?php

declare(strict_types=1);

namespace CrucibleConformance\Equality;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

use const NAN;

final class EqualityTest extends TestCase
{
    public function testNumericStringJugglesWithNumber(): void
    {
        $this->assertEquals('1', 1);
        $this->assertEquals(1.0, 1);
    }

    public function testTwoStringsDoNotJuggle(): void
    {
        $this->assertNotEquals('1', '01');
    }

    public function testNanIsNeverEqual(): void
    {
        $this->assertNotEquals(NAN, NAN);
    }

    public function testArraysCompareByKeysOrderInsensitive(): void
    {
        $this->assertEquals(['a' => 1, 'b' => 2], ['b' => 2, 'a' => 1]);
        $this->assertNotEquals([1, 2], [2, 1]);
    }

    public function testCanonicalizingSortsBeforeComparing(): void
    {
        $this->assertEqualsCanonicalizing([3, 1, 2], [1, 2, 3]);
    }

    public function testDeltaRecursesIntoArrays(): void
    {
        $this->assertEqualsWithDelta(1.0, 1.04, 0.05);
        $this->assertEqualsWithDelta(['x' => 1.0], ['x' => 1.04], 0.05);
    }

    public function testDateTimesCompareByInstant(): void
    {
        $this->assertEquals(
            new DateTimeImmutable('2026-07-14T12:00:00+00:00'),
            new DateTimeImmutable('2026-07-14T14:00:00+02:00'),
        );
    }

    public function testIdentityStaysStrict(): void
    {
        $this->assertNotSame('1', 1);
        $this->assertSame(3, 3);
    }

    public function testCaseInsensitiveEquality(): void
    {
        $this->assertEqualsIgnoringCase('Crucible', 'crucible');
    }
}
