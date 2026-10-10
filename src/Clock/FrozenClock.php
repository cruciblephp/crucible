<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Clock;

use DateTimeImmutable;

/**
 * A clock that always reports the same instant — the test-side Clock,
 * making event streams byte-reproducible.
 */
final readonly class FrozenClock implements Clock
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
