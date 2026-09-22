<?php

declare(strict_types=1);

namespace CrucibleConformance\AssertionTail;

use PHPUnit\Framework\TestCase;

final class Money
{
    public function __construct(private int $amount) {}

    public function equals(self $other): bool
    {
        return $other->amount === $this->amount;
    }

    public function sameSign(self $other): bool
    {
        return ($other->amount <=> 0) === ($this->amount <=> 0);
    }
}

final class ObjectNotEqualsTest extends TestCase
{
    public function testHoldsWhenTheProtocolSaysUnequal(): void
    {
        $this->assertObjectNotEquals(new Money(1), new Money(2));
    }

    public function testFailsWhenTheProtocolSaysEqual(): void
    {
        $this->assertObjectNotEquals(new Money(1), new Money(1));
    }

    public function testHonoursANamedComparisonMethod(): void
    {
        $this->assertObjectNotEquals(new Money(1), new Money(-1), 'sameSign');
    }

    public function testFailsOnANamedComparisonMethodThatAgrees(): void
    {
        $this->assertObjectNotEquals(new Money(1), new Money(5), 'sameSign');
    }
}
