<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use LucianoPereira\Crucible\Version;

use const PHP_VERSION;

/**
 * Always the first event of a stream (the test2json guarantee: a
 * stream begins with a start event).
 */
final readonly class RunStarted implements Event
{
    /**
     * @param int $tests how many tests the run plans to execute, for the
     *                   readers that show progress against a total
     */
    public function __construct(
        public string $crucibleVersion = Version::NUMBER,
        public string $phpVersion = PHP_VERSION,
        public int $tests = 0,
    ) {}

    public function name(): EventName
    {
        return EventName::RunStarted;
    }

    public function payload(): array
    {
        $payload = [
            'crucible' => $this->crucibleVersion,
            'php'      => $this->phpVersion,
        ];

        if ($this->tests > 0) {
            $payload['tests'] = $this->tests;
        }

        return $payload;
    }
}
