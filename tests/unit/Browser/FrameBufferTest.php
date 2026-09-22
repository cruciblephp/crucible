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
use LucianoPereira\Crucible\Browser\Playwright\FrameBuffer;
use LucianoPereira\Crucible\Framework\TestCase;

use function pack;
use function strlen;
use function substr;

#[CoversClass(FrameBuffer::class)]
final class FrameBufferTest extends TestCase
{
    public function testFramesEmergeOnlyWhenComplete(): void
    {
        $buffer = new FrameBuffer();
        $wire   = pack('V', 8) . '{"id":1}';

        $this->assertNull($buffer->next());

        $buffer->append(substr($wire, 0, 3)); // partial length prefix
        $this->assertNull($buffer->next());

        $buffer->append(substr($wire, 3, 5)); // prefix + partial payload
        $this->assertNull($buffer->next());

        $buffer->append(substr($wire, 8));
        $this->assertSame(['id' => 1], $buffer->next());
        $this->assertNull($buffer->next());
    }

    public function testSeveralFramesInOneChunk(): void
    {
        $buffer = new FrameBuffer();
        $one    = '{"id":1}';
        $two    = '{"id":2,"result":{"ok":true}}';
        $buffer->append(pack('V', strlen($one)) . $one . pack('V', strlen($two)) . $two);

        $this->assertSame(['id' => 1], $buffer->next());
        $this->assertSame(['id' => 2, 'result' => ['ok' => true]], $buffer->next());
        $this->assertNull($buffer->next());
    }

    public function testGarbagePayloadIsANamedProtocolError(): void
    {
        $buffer = new FrameBuffer();
        $buffer->append(pack('V', 4) . 'oops');

        $this->expectException(BrowserProtocolException::class);
        $this->expectExceptionMessage('not valid JSON');

        $buffer->next();
    }
}
