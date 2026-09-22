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

/**
 * A chunk of output produced by a test. The test2json guarantee holds:
 * the concatenation of all chunks for a test, in sequence order, is
 * the exact output of that test — chunking never loses or reorders
 * bytes.
 */
final readonly class TestOutputWritten implements Event
{
    public function __construct(
        public TestId $test,
        public OutputChannel $channel,
        public string $chunk,
    ) {}

    public function name(): EventName
    {
        return EventName::TestOutputWritten;
    }

    public function payload(): array
    {
        return [
            'id'      => $this->test->toString(),
            'channel' => $this->channel->value,
            'chunk'   => $this->chunk,
        ];
    }
}
