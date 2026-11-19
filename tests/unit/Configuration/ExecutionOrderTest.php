<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Configuration;

use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Framework\TestCase;

/**
 * Which orderings answer from the recorded history.
 *
 * A worker re-schedules its own unit, and the cache it would read is
 * the parent's `results.json` — a file that grows with the suite. An
 * ordering that never consults it must not pay for the read and the
 * decode, so the answer to "which orderings read history" lives in one
 * place both sides can ask.
 */
final class ExecutionOrderTest extends TestCase
{
    public function testOnlyTheTwoHistoryAwareOrderingsReadTheCache(): void
    {
        $reading = [];

        foreach (ExecutionOrder::cases() as $order) {
            if ($order->needsHistory()) {
                $reading[] = $order->value;
            }
        }

        self::assertSame(
            ['defects', 'duration'],
            $reading,
            'Adding an ordering that scores past outcomes must extend needsHistory(), or a worker will silently order without its history.',
        );
    }
}
