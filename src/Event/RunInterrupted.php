<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * The run is ending early — a signal, a --bail condition, or a
 * cooperative halt. Emitted before RunFinished so consumers can
 * distinguish "stopped" from "completed".
 */
final readonly class RunInterrupted implements Event
{
    /**
     * @param non-empty-string $reason e.g. "signal:SIGINT", "stop-on-failure"
     */
    public function __construct(
        public string $reason,
    ) {}

    public function name(): EventName
    {
        return EventName::RunInterrupted;
    }

    public function payload(): array
    {
        return ['reason' => $this->reason];
    }
}
