<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Framework;

use DomainException;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Framework\TestCase;
use Throwable;

/**
 * expectExceptionObject() sets the class, the message and the code of one
 * exception object as three expectations, as the incumbent does. A probe
 * TestCase runs the mismatches, so each failure is read as a value.
 */
final class ExpectExceptionObjectTest extends TestCase
{
    public function test_a_matching_exception_meets_all_three(): never
    {
        $this->expectExceptionObject(new DomainException('invoice details incomplete', 422));

        throw new DomainException('invoice details incomplete', 422);
    }

    public function test_another_message_is_a_failure(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that exception message "availability" contains "invoice details incomplete".');

        $this->probe(new DomainException('availability', 422))->invokeTest('test_probe');
    }

    public function test_another_code_is_a_failure(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('Failed asserting that exception code "500" is equal to "422".');

        $this->probe(new DomainException('invoice details incomplete', 500))->invokeTest('test_probe');
    }

    /**
     * A test that expects DomainException('invoice details incomplete', 422) and throws $thrown.
     */
    private function probe(Throwable $thrown): TestCase
    {
        return new class ($thrown) extends TestCase {
            public function __construct(private readonly Throwable $thrown) {}

            public function test_probe(): never
            {
                $this->expectExceptionObject(new DomainException('invoice details incomplete', 422));

                throw $this->thrown;
            }
        };
    }
}
