<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Configuration;

/**
 * Replaces the string-typed executionOrder attribute of phpunit.xml
 * with a native backed enum: invalid values are impossible, not a
 * runtime validation error.
 */
enum ExecutionOrder: string
{
    case Declared      = 'default';
    case DefectsFirst  = 'defects';
    case Duration      = 'duration';
    case Random        = 'random';
    case Reversed      = 'reverse';
    case SizeAscending = 'size';

    /**
     * Whether this ordering answers from the recorded run history
     * (`.crucible.cache/results.json`). Only these two do: `defects`
     * scores past outcomes and `duration` predicts the next run from
     * the last one. The others are pure functions of the plan, which
     * is what lets a worker skip reading a file whose answer it would
     * never consult.
     */
    public function needsHistory(): bool
    {
        return $this === self::DefectsFirst || $this === self::Duration;
    }
}
