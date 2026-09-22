<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

final readonly class SuiteFinished implements Event
{
    /**
     * @param non-empty-string $suite
     * @param float            $duration wall-clock seconds, from monotonic time
     */
    public function __construct(
        public string $suite,
        public float $duration,
    ) {}

    public function name(): EventName
    {
        return EventName::SuiteFinished;
    }

    public function payload(): array
    {
        return ['suite' => $this->suite, 'duration' => $this->duration];
    }
}
