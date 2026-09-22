<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Browser;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Browser\BrowserProtocolException;
use LucianoPereira\Crucible\Browser\Playwright\FrameStream;
use LucianoPereira\Crucible\Framework\TestCase;

use function fopen;
use function fwrite;
use function pack;
use function rewind;

#[CoversClass(FrameStream::class)]
final class FrameStreamTest extends TestCase
{
    public function testRoundTripsAMessage(): void
    {
        $stream = $this->stream();
        FrameStream::write($stream, ['id' => 1, 'guid' => '', 'method' => 'initialize']);
        rewind($stream);

        $this->assertSame(
            ['id' => 1, 'guid' => '', 'method' => 'initialize'],
            FrameStream::read($stream),
        );
    }

    public function testRoundTripsSeveralFramesInSequence(): void
    {
        $stream = $this->stream();
        FrameStream::write($stream, ['id' => 1]);
        FrameStream::write($stream, ['id' => 2, 'params' => ['url' => 'https://example.test']]);
        rewind($stream);

        $this->assertSame(['id' => 1], FrameStream::read($stream));
        $this->assertSame(['id' => 2, 'params' => ['url' => 'https://example.test']], FrameStream::read($stream));
    }

    public function testShortLengthPrefixIsANamedProtocolError(): void
    {
        $stream = $this->stream();
        fwrite($stream, "\x05\x00");
        rewind($stream);

        $this->expectException(BrowserProtocolException::class);
        $this->expectExceptionMessage('length prefix');

        FrameStream::read($stream);
    }

    public function testTruncatedPayloadIsANamedProtocolError(): void
    {
        $stream = $this->stream();
        fwrite($stream, pack('V', 10) . '{"id"');
        rewind($stream);

        $this->expectException(BrowserProtocolException::class);
        $this->expectExceptionMessage('frame payload');

        FrameStream::read($stream);
    }

    public function testNonJsonPayloadIsANamedProtocolError(): void
    {
        $stream = $this->stream();
        fwrite($stream, pack('V', 4) . 'oops');
        rewind($stream);

        $this->expectException(BrowserProtocolException::class);
        $this->expectExceptionMessage('not valid JSON');

        FrameStream::read($stream);
    }

    /**
     * @return resource
     */
    private function stream()
    {
        $stream = fopen('php://temp', 'r+b');
        $this->assertNotFalse($stream);

        return $stream;
    }
}
