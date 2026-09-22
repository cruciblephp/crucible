<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use LucianoPereira\Crucible\Test\TestId;

final readonly class TestStarted implements Event
{
    /**
     * @param positive-int $attempt 1 on the first run; >1 on retries
     */
    public function __construct(
        public TestId $test,
        public int $attempt = 1,
    ) {}

    public function name(): EventName
    {
        return EventName::TestStarted;
    }

    public function payload(): array
    {
        $payload = ['id' => $this->test->toString()];

        if ($this->attempt > 1) {
            $payload['attempt'] = $this->attempt;
        }

        return $payload;
    }
}
