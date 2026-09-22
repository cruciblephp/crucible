<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Clock;

use DateTimeImmutable;

/**
 * Wall-clock abstraction so event timestamps are injectable and the
 * stream is byte-reproducible in tests. Durations are never derived
 * from this clock — they come from monotonic time at the call site.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
