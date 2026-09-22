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
use LucianoPereira\Crucible\Browser\Playwright\JsValue;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(JsValue::class)]
final class JsValueTest extends TestCase
{
    public function testScalars(): void
    {
        $this->assertSame('Batch2', JsValue::decode(['s' => 'Batch2']));
        $this->assertSame(7, JsValue::decode(['n' => 7]));
        $this->assertTrue(JsValue::decode(['b' => true]));
        $this->assertNull(JsValue::decode(['v' => 'undefined']));
        $this->assertNull(JsValue::decode(['v' => 'null']));
    }

    public function testTheProbePinnedObjectShape(): void
    {
        // The exact envelope observed for ({ok: true, n: 7, list: [1, "two"]})
        $decoded = JsValue::decode([
            'o' => [
                ['k' => 'ok', 'v' => ['b' => true]],
                ['k' => 'n', 'v' => ['n' => 7]],
                ['k' => 'list', 'v' => ['a' => [['n' => 1], ['s' => 'two']], 'id' => 2]],
            ],
            'id' => 1,
        ]);

        $this->assertSame(['ok' => true, 'n' => 7, 'list' => [1, 'two']], $decoded);
    }

    public function testUnknownEnvelopeIsANamedError(): void
    {
        $this->expectException(BrowserProtocolException::class);
        $this->expectExceptionMessage('Unknown value envelope');

        JsValue::decode(['h' => 42]);
    }
}
