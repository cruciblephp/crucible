<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\WorkerStderrChannel;

use function file_exists;
use function file_put_contents;
use function is_resource;
use function microtime;
use function proc_close;
use function proc_open;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

use const PHP_BINARY;

/**
 * The Windows shape is a per-worker file that the supervisor reads by
 * offset; the pipe shape must stay selectable. Both are exercised here,
 * on every platform.
 */
#[CoversClass(WorkerStderrChannel::class)]
final class WorkerStderrChannelTest extends TestCase
{
    public function testFileChannelReadsWhatTheChildWritesByOffset(): void
    {
        $channel = WorkerStderrChannel::file();

        self::assertNotNull($channel->path);
        self::assertNull($channel->selectable(), 'A file is not selectable.');

        $script = tempnam(sys_get_temp_dir(), 'crucible-stderr-');

        if ($script === false) {
            self::fail('Cannot create a temp file.');
        }

        file_put_contents(
            $script,
            '<?php fwrite(STDERR, "first"); usleep(100000); fwrite(STDERR, "second");',
        );

        $process = proc_open([PHP_BINARY, $script], $channel->descriptors(), $pipes, sys_get_temp_dir());

        self::assertTrue(is_resource($process));
        self::assertTrue($channel->bind($pipes));

        $read     = '';
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline && $read !== 'firstsecond') {
            $read .= $channel->read();
            usleep(5_000);
        }

        self::assertSame('firstsecond', $read, 'The file must be read as bytes, in write order.');

        proc_close($process);
        $channel->close();

        self::assertFalse(file_exists($channel->path), 'A clean close removes the log.');

        @unlink($script);
    }

    public function testCloseCanKeepTheLogForAFailedWorker(): void
    {
        $channel = WorkerStderrChannel::file();
        $path    = (string) $channel->path;

        $channel->close(keep: true);

        self::assertTrue(file_exists($path), 'A kept log must survive for inspection.');

        @unlink($path);
    }
}
