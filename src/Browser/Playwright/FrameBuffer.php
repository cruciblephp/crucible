<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser\Playwright;

use JsonException;
use LucianoPereira\Crucible\Browser\BrowserProtocolException;

use function is_array;
use function json_decode;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

use const JSON_THROW_ON_ERROR;

/**
 * Incremental decoder for the driver's wire framing (4-byte LE length
 * prefix + JSON): bytes arrive in whatever chunks the pipe delivers,
 * frames come out whole. The non-blocking counterpart of
 * FrameStream::read — the select-loop transport needs to read what is
 * available and ask "is a frame complete yet?".
 */
final class FrameBuffer
{
    private string $buffer = '';

    public function append(string $chunk): void
    {
        $this->buffer .= $chunk;
    }

    /**
     * The next complete frame, or null until more bytes arrive.
     *
     * @return array<string, mixed>|null
     */
    public function next(): ?array
    {
        if (strlen($this->buffer) < 4) {
            return null;
        }

        /** @var array{1: int<0, max>} $unpacked */
        $unpacked = unpack('V', substr($this->buffer, 0, 4));
        $length   = $unpacked[1];

        if (strlen($this->buffer) < 4 + $length) {
            return null;
        }

        $payload      = substr($this->buffer, 4, $length);
        $this->buffer = substr($this->buffer, 4 + $length);

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BrowserProtocolException(sprintf('Frame is not valid JSON: %s', $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (!is_array($decoded)) {
            throw new BrowserProtocolException('Frame decoded to a non-object payload.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
