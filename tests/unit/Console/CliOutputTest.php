<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Console;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Output\CliOutput;
use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Terminal\FakeTerminal;
use LucianoPereira\Crucible\Framework\TestCase;

use const PHP_EOL;

/**
 * Pre-run CLI output goes through the terminal writer, not PHP's
 * print/printf, so it cannot mark HTTP headers as sent (F-01).
 */
#[CoversClass(CliOutput::class)]
final class CliOutputTest extends TestCase
{
    private FakeTerminal $terminal;

    protected function setUp(): void
    {
        $this->terminal = new FakeTerminal();

        Runtime::setTerminal($this->terminal);
    }

    protected function tearDown(): void
    {
        Runtime::reset();
    }

    public function testLineWritesTheTextAndASeparator(): void
    {
        CliOutput::line('hello');

        self::assertSame('hello' . PHP_EOL, $this->terminal->output());
    }

    public function testWriteDoesNotAddASeparator(): void
    {
        CliOutput::write('hello');

        self::assertSame('hello', $this->terminal->output());
    }

    public function testAnEmptyLineIsTheSeparatorAlone(): void
    {
        CliOutput::line();

        self::assertSame(PHP_EOL, $this->terminal->output());
    }
}
