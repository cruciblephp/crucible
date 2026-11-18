<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Console\Output;

use LucianoPereira\Crucible\Console\Runtime\Runtime;

use const PHP_EOL;

/**
 * CLI output that does not send HTTP headers.
 *
 * PHP's `print`/`printf`/`echo` write through the output layer, and the
 * first byte marks `headers_sent()` for the rest of the process (F-01,
 * ticket #2 item 1). A test that boots a production kernel and starts a
 * native session then fails with "headers have already been sent ...".
 * PHPUnit writes to the STDOUT stream, which bypasses that layer; so does
 * the terminal writer, so pre-run output goes through it — colour, plain
 * and errors alike. Reporter output after the run is out of scope: no
 * kernel is booted then.
 */
final class CliOutput
{
    public static function write(string $text): void
    {
        Runtime::terminal()->write($text);
    }

    public static function line(string $text = ''): void
    {
        self::write($text . PHP_EOL);
    }
}
