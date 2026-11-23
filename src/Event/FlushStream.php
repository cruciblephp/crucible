<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Event;

use function fflush;

/**
 * Flushes a stream before every event frame. The worker's stderr is
 * written by the test; on Windows that stream is a file, and a buffered
 * write could otherwise reach it after the frame that closes the test —
 * the supervisor would then attribute the text to the next test or lose
 * it with a clean run. Subscribed before the NDJSON writer, this makes
 * "the test wrote it before it finished" true in the bytes too.
 */
final class FlushStream implements Listener
{
    /**
     * @param resource $stream
     */
    public function __construct(private $stream) {}

    public function handle(Envelope $envelope): void
    {
        fflush($this->stream);
    }
}
