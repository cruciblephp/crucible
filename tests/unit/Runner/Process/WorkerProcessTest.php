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
use LucianoPereira\Crucible\Attributes\RequiresPhpExtension;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\Process\WorkerManifest;
use LucianoPereira\Crucible\Runner\Process\WorkerProcess;
use LucianoPereira\Crucible\Runner\Process\WorkUnit;

use function file_put_contents;
use function is_file;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

/**
 * A worker that dies by signal leaves no stderr of its own (a crashing
 * coverage driver, D-041), so the signal is the only diagnosis there is.
 */
#[CoversClass(WorkerProcess::class)]
final class WorkerProcessTest extends TestCase
{
    private string $script = '';

    protected function tearDown(): void
    {
        if ($this->script !== '' && is_file($this->script)) {
            unlink($this->script);
        }
    }

    #[RequiresPhpExtension('posix')]
    public function testCloseReportsTheSignalThatKilledTheWorker(): void
    {
        $worker = $this->spawn('posix_kill(getmypid(), 11);');

        self::assertSame(11, $worker->close());
        self::assertSame(11, $worker->signal());
    }

    public function testCloseKeepsANormalExitCodeAndNoSignal(): void
    {
        $worker = $this->spawn('exit(3);');

        self::assertSame(3, $worker->close());
        self::assertNull($worker->signal());
    }

    private function spawn(string $body): WorkerProcess
    {
        $script = tempnam(sys_get_temp_dir(), 'crucible-worker-');

        if ($script === false) {
            self::fail('Cannot create a temp file.');
        }

        $this->script = $script;
        file_put_contents($script, '<?php stream_get_contents(STDIN); ' . $body);

        $worker = WorkerProcess::spawn(
            $script,
            new WorkingDirectory(sys_get_temp_dir()),
            new WorkerManifest('/project/crucible.php', [new WorkUnit('tests/FooTest.php')]),
            [],
        );

        self::assertInstanceOf(WorkerProcess::class, $worker);

        while (!$worker->atEof()) {
            $worker->readLines();
            usleep(1_000);
        }

        return $worker;
    }
}
