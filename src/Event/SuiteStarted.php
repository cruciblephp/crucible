<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

final readonly class SuiteStarted implements Event
{
    /**
     * @param non-empty-string $suite
     */
    public function __construct(
        public string $suite,
    ) {}

    public function name(): EventName
    {
        return EventName::SuiteStarted;
    }

    public function payload(): array
    {
        return ['suite' => $this->suite];
    }
}
