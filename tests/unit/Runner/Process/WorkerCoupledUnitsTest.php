<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner\Process;

use LucianoPereira\Crucible\Framework\TestCase;

use function chmod;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_dir;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function mkdir;
use function proc_close;
use function proc_open;
use function realpath;
use function rmdir;
use function scandir;
use function sort;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A worker runs the tests its units name — and a suite may couple
 * files with nothing but a call. A helper function declared in one
 * test file and used from another's body is satisfied in the
 * sequential process by the accident of discovery loading every
 * file; a worker that loads only its own units used to die with
 * "Call to undefined function", failing in `--parallel` what the
 * same suite passed sequentially, and what the pre-change worker
 * passed by rediscovering the whole suite.
 *
 * The parent names, per unit, the other discovered files whose
 * declared symbols the unit's file uses, and the worker requires
 * them before discovery without running them. The counters pin the
 * honest price: a coupled file loads once more, in the caller's
 * worker; an uncoupled suite loads exactly its own files
 * (WorkerUnitDiscoveryTest).
 */
final class WorkerCoupledUnitsTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-coupled-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('unit', 'tests')
                ->build();
            PHP);

        // The callee: declares the helper at file scope, next to its
        // own test that uses it.
        file_put_contents($this->project . '/tests/AlphaTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            file_put_contents(__DIR__ . '/../counters.log', "load alpha\n", FILE_APPEND);

            function alpha_greeting(): string
            {
                return 'from alpha';
            }

            final class AlphaTest extends TestCase
            {
                public function testAlphaGreets(): void
                {
                    \assert(alpha_greeting() === 'from alpha');
                    self::assertSame('from alpha', alpha_greeting());
                }
            }
            PHP);

        // The caller: no declaration of its own — the coupling is the
        // call, and nothing else.
        file_put_contents($this->project . '/tests/BetaTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            file_put_contents(__DIR__ . '/../counters.log', "load beta\n", FILE_APPEND);

            final class BetaTest extends TestCase
            {
                public function testGreetsViaAlpha(): void
                {
                    self::assertSame('from alpha', alpha_greeting());
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testACoupledSuiteBehavesInParallelAsItDoesSequentially(): void
    {
        // The sequential truth: one process, every file loaded by
        // discovery, the call answered.
        [$exit, , $events] = $this->crucible([]);

        self::assertSame(0, $exit, 'The sequential run is the behavioural baseline.');
        $sequential = $this->ranIds($events);

        // The parallel twin must answer identically. Before the
        // preload this died in Beta's worker with a fatal "Call to
        // undefined function" and the run exited 2.
        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame($sequential, $this->ranIds($events), 'A parallel run executes the sequential plan exactly.');

        // The honest price, pinned: the parent discovers both files,
        // Alpha's own worker loads it again to run it, and Beta's
        // worker preloads it so the call resolves — one extra load,
        // paid only by the coupling.
        self::assertSame([3, 2], $this->counters(), 'Alpha loads three times (parent, its worker, the caller\'s worker preload); Beta twice (parent, its worker).');
    }

    public function testAFilteredParallelRunStillPreloadsTheFileItCoupledTo(): void
    {
        // The filter leaves Beta the only selected unit — but
        // sequential discovery still loaded Alpha, whatever the
        // filter removed, so the call was answered there. The
        // preload map must be built over the discovery, not over the
        // selection, or the filtered parallel run dies where its
        // sequential twin passes.
        [$exit, , $events] = $this->crucible(['--filter', 'testGreetsViaAlpha']);

        self::assertSame(0, $exit);
        $sequential = $this->ranIds($events);
        self::assertSame(['tests/BetaTest.php::testGreetsViaAlpha'], $sequential);

        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2', '--filter', 'testGreetsViaAlpha']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame($sequential, $this->ranIds($events), 'The filtered parallel run must answer what its sequential twin answers.');

        // The only unit is Beta, and the only worker is its own: the
        // parent discovered both files, the worker preloaded Alpha.
        self::assertSame([2, 2], $this->counters());
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<string>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);
        @unlink($this->project . '/counters.log');

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 4) . '/crucible', ...$arguments, '--log-events-json', $events],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit     = proc_close($process);
        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        if ($contents === '') {
            self::fail('No event stream was written. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        /** @var list<string> $lines */
        $lines = [];

        foreach (explode("\n", $contents) as $line) {
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return [$exit, $stdout, $lines];
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function ranIds(array $events): array
    {
        $ids = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $ids[] = $decoded['id'];
            }
        }

        sort($ids);

        return $ids;
    }

    /**
     * @return array{int, int}
     */
    private function counters(): array
    {
        $log = (string) file_get_contents($this->project . '/counters.log');

        return [
            substr_count($log, 'load alpha'),
            substr_count($log, 'load beta'),
        ];
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
