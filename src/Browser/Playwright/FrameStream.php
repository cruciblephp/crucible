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

use function fread;
use function fwrite;
use function is_array;
use function json_decode;
use function json_encode;
use function pack;
use function sprintf;
use function strlen;
use function unpack;

use const JSON_THROW_ON_ERROR;

/**
 * The Playwright driver's wire framing, pinned black-box (probe
 * 2026-07-16): each message is a 4-byte little-endian length prefix
 * followed by that many bytes of JSON. Pure stream-in/stream-out so
 * the framing is testable without a driver process.
 */
final readonly class FrameStream
{
    /**
     * @param resource             $stream
     * @param array<string, mixed> $message
     */
    public static function write($stream, array $message): void
    {
        try {
            $json = json_encode($message, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BrowserProtocolException(sprintf('Message cannot be encoded: %s', $e->getMessage()), $e->getCode(), previous: $e);
        }

        if (fwrite($stream, pack('V', strlen($json)) . $json) === false) {
            throw new BrowserProtocolException('The driver pipe is not writable; the process likely exited.');
        }
    }

    /**
     * Blocking read of exactly one frame.
     *
     * @param resource $stream
     *
     * @return array<string, mixed>
     */
    public static function read($stream): array
    {
        $prefix = self::exactly($stream, 4, 'length prefix');
        /** @var array{1: int<0, max>} $unpacked */
        $unpacked = unpack('V', $prefix);
        $payload  = self::exactly($stream, $unpacked[1], 'frame payload');

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

    /**
     * @param resource         $stream
     * @param non-empty-string $what
     */
    private static function exactly($stream, int $bytes, string $what): string
    {
        $buffer = '';

        while (($remaining = $bytes - strlen($buffer)) > 0) {
            $chunk = fread($stream, $remaining);

            if ($chunk === false || $chunk === '') {
                throw new BrowserProtocolException(sprintf(
                    'Short read on %s (%d of %d bytes) — the driver stream ended early.',
                    $what,
                    strlen($buffer),
                    $bytes,
                ));
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }
}
