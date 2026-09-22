<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use function fwrite;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PRESERVE_ZERO_FRACTION;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Serializes the stream as newline-delimited JSON, one envelope per
 * line, written unbuffered so live consumers see events as they
 * happen (the test2json/Node model).
 *
 * - JSON_PRESERVE_ZERO_FRACTION: durations are floats and stay floats.
 * - JSON_INVALID_UTF8_SUBSTITUTE: invalid bytes in test output are
 *   replaced, never fatal (test2json replaces invalid UTF-8 too).
 */
final class NdjsonWriter implements Listener
{
    private const int JSON_FLAGS = JSON_THROW_ON_ERROR
        | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param resource $stream an open, writable stream (STDOUT, a file, a pipe)
     */
    public function __construct(private $stream) {}

    public function handle(Envelope $envelope): void
    {
        fwrite($this->stream, json_encode($envelope->toArray(), self::JSON_FLAGS) . "\n");
    }
}
