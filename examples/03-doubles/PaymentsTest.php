<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Examples\Doubles;

use LucianoPereira\Crucible\Framework\TestCase;

interface Gateway
{
    public function charge(int $cents): bool;

    public function name(): string;
}

final readonly class Checkout
{
    public function __construct(private Gateway $gateway) {}

    public function pay(int $cents): string
    {
        return $this->gateway->charge($cents)
            ? 'paid via ' . $this->gateway->name()
            : 'declined';
    }
}

/**
 * Test doubles.
 *
 * A **stub** answers questions; a **mock** also has expectations about
 * how it is called, verified at the end of the test whether or not you
 * remember to check. The distinction matters: a stub that is never
 * called is fine, a mock with an unmet expectation is a failure.
 */
final class PaymentsTest extends TestCase
{
    public function testAStubAnswersWithoutBeingVerified(): void
    {
        $gateway = $this->createStub(Gateway::class);
        $gateway->method('charge')->willReturn(true);
        $gateway->method('name')->willReturn('stripe');

        $this->assertSame('paid via stripe', (new Checkout($gateway))->pay(500));
    }

    public function testAMockVerifiesHowItWasCalled(): void
    {
        $gateway = $this->createMock(Gateway::class);

        // The expectation is the assertion: if charge() is never called,
        // or called with something other than 500, the test fails at the
        // end without any further assertion from us.
        $gateway->expects($this->once())
            ->method('charge')
            ->with(500)
            ->willReturn(true);

        $gateway->method('name')->willReturn('adyen');

        (new Checkout($gateway))->pay(500);
    }

    public function testUnconfiguredMethodsReturnUsableDefaults(): void
    {
        // charge() was never configured, so it returns the type's
        // default — false for bool — rather than null or an error.
        $gateway = $this->createStub(Gateway::class);

        $this->assertSame('declined', (new Checkout($gateway))->pay(100));
    }

    public function testConsecutiveCallsCanDifferentiate(): void
    {
        $gateway = $this->createStub(Gateway::class);
        $gateway->method('charge')->willReturnOnConsecutiveCalls(false, true);
        $gateway->method('name')->willReturn('worldpay');

        $checkout = new Checkout($gateway);

        $this->assertSame('declined', $checkout->pay(100));
        $this->assertSame('paid via worldpay', $checkout->pay(100));
    }
}
