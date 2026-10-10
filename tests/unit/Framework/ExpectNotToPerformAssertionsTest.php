<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Framework;

use LucianoPereira\Crucible\Framework\TestCase;

/**
 * expectNotToPerformAssertions() is the in-body form of
 * #[DoesNotPerformAssertions]: a test that says it asserts nothing is not
 * risky for asserting nothing. Run with the default reportUselessTests.
 */
final class ExpectNotToPerformAssertionsTest extends TestCase
{
    public function test_the_declaration_alone_is_not_risky(): void
    {
        $this->expectNotToPerformAssertions();
    }

    public function test_a_reset_counter_keeps_the_declaration(): void
    {
        $this->expectNotToPerformAssertions();

        // User code may reset the counter: the declaration is not the counter's.
        self::resetAssertionCount();
    }
}
