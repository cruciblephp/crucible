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
use LucianoPereira\Crucible\Runner\Process\WorkerManifest;
use LucianoPereira\Crucible\Runner\Process\WorkUnit;

use function chmod;
use function dirname;
use function fclose;
use function file_put_contents;
use function fwrite;
use function getenv;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A coverage worker without a driver used to run its tests and write no
 * artifact. The parent merges by glob, so fewer files looked like a
 * smaller suite — and a floor judged a fraction as the whole.
 */
#[CoversClass(WorkerManifest::class)]
final class WorkerCoverageArtifactTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-coverage-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests')->build();
            PHP);

        file_put_contents($this->project . '/tests/PassingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class PassingTest extends TestCase
            {
                public function testPasses(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testAWorkerWithNoCoverageDriverFailsBeforeRunningTests(): void
    {
        $artifact = $this->project . '/coverage.json';
        $manifest = new WorkerManifest(
            $this->project . '/crucible.php',
            [new WorkUnit('tests/PassingTest.php')],
            coverageArtifact: $artifact,
        );

        // Force the child into "no driver" on every machine: pcov off in
        // the ini, xdebug out of coverage mode through both the ini and
        // its environment override, and the environment override unset.
        $process = proc_open(
            [
                PHP_BINARY,
                '-d', 'pcov.enabled=0',
                '-d', 'xdebug.mode=develop',
                dirname(__DIR__, 4) . '/crucible',
                '--worker',
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb', 'XDEBUG_MODE' => ''],
        );

        if (!is_resource($process)) {
            self::fail('The worker could not be started.');
        }

        fwrite($pipes[0], $manifest->toJson());
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        self::assertSame(2, $exit, 'A coverage worker with no driver must not run. stdout: ' . $stdout);
        self::assertStringContainsString('Coverage:', $stderr, 'The reason a worker cannot collect was dropped.');
        self::assertFalse(is_file($artifact), 'A worker that collected nothing wrote an artifact anyway.');
    }

    /** Git leaves its objects read-only, which Windows will not unlink until told otherwise. */
    private function remove(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                chmod($path, 0o666);
                unlink($path);
            }

            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_string($entry)) {
                $this->remove($path . '/' . $entry);
            }
        }

        rmdir($path);
    }
}
