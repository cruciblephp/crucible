<?php

declare(strict_types=1);

namespace CrucibleConformance\Doubles;

use PHPUnit\Framework\TestCase;

interface Gateway
{
    public function charge(int $cents): bool;

    public function balance(): int;

    public function label(): string;
}

final class DoublesTest extends TestCase
{
    public function testStubConfiguredAndDefaultReturns(): void
    {
        $gateway = $this->createStub(Gateway::class);
        $gateway->method('charge')->willReturn(true);

        $this->assertTrue($gateway->charge(100));
        $this->assertSame(0, $gateway->balance());
        $this->assertSame('', $gateway->label());
    }

    public function testConfiguredStubShorthand(): void
    {
        $gateway = $this->createConfiguredStub(Gateway::class, ['balance' => 250]);

        $this->assertSame(250, $gateway->balance());
    }

    public function testConsecutiveReturns(): void
    {
        $gateway = $this->createStub(Gateway::class);
        $gateway->method('balance')->willReturnOnConsecutiveCalls(1, 2);

        $this->assertSame(1, $gateway->balance());
        $this->assertSame(2, $gateway->balance());
    }

    public function testMockExpectationSatisfied(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->expects($this->once())->method('charge')->willReturn(true);

        $this->assertTrue($gateway->charge(5));
    }

    public function testUnmetExpectationFails(): void
    {
        $gateway = $this->createMock(Gateway::class);
        $gateway->expects($this->once())->method('charge');

        $this->assertSame(0, $gateway->balance());
        // charge() never called: the test must fail at verification.
    }
}
