<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Attributes\Large;
use LucianoPereira\Crucible\Attributes\Medium;
use LucianoPereira\Crucible\Attributes\Small;
use LucianoPereira\Crucible\Metadata\MetadataCollection;

/**
 * The spec's --enforce-time-limit: a test that runs longer than its
 * declared size allows is risky. The budgets are the spec's — one
 * second for #[Small], ten for #[Medium], sixty for #[Large] — and
 * --default-time-limit sets the one for a test that declares no size.
 *
 * Measured rather than interrupted. The spec aborts the test mid-flight
 * with a pcntl alarm; Crucible judges the duration it already records,
 * so the budget needs no extension, holds identically inside a worker,
 * and the message says what actually happened. A test that hangs
 * forever is a hang either way — no alarm survives a blocking syscall.
 */
final readonly class TimeLimit
{
    private const int SMALL  = 1;
    private const int MEDIUM = 10;
    private const int LARGE  = 60;

    /**
     * The budget in seconds, or null when nothing bounds this test:
     * no size declared and no --default-time-limit given.
     */
    public static function forTest(MetadataCollection $metadata, ?int $default): ?int
    {
        return match (true) {
            $metadata->has(Small::class)  => self::SMALL,
            $metadata->has(Medium::class) => self::MEDIUM,
            $metadata->has(Large::class)  => self::LARGE,
            default                       => $default !== null && $default > 0 ? $default : null,
        };
    }
}
