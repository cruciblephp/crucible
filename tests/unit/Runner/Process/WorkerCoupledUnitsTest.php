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
use function str_replace;
use function stream_get_contents;
use function substr_count;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * The worker must preserve discovery order for a suite with references
 * between test files, even when the callee's tests were filtered out.
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

        // The parallel twin must answer identically. Before full
        // discovery this died in Beta's worker with a fatal "Call to
        // undefined function" and the run exited 2.
        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame($sequential, $this->ranIds($events), 'A parallel run executes the sequential plan exactly.');

        // Each worker repeats discovery for a coupled suite, then runs its own unit.
        self::assertSame([3, 3], $this->counters());
    }

    public function testAFilteredParallelRunStillDiscoversTheFileItCoupledTo(): void
    {
        [$exit, , $events] = $this->crucible(['--filter', 'testGreetsViaAlpha']);

        self::assertSame(0, $exit);
        $sequential = $this->ranIds($events);
        self::assertSame(['tests/BetaTest.php::testGreetsViaAlpha'], $sequential);

        [$exit, $stdout, $events] = $this->crucible(['--parallel', '2', '--filter', 'testGreetsViaAlpha']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame($sequential, $this->ranIds($events), 'The filtered parallel run must answer what its sequential twin answers.');

        // The only unit is Beta, and the only worker is its own: the
        // parent discovered both files, the worker discovered both before running Beta.
        self::assertSame([2, 2], $this->counters());
    }

    public function testCrossFileFunctionCallsUsePhpCaseInsensitivity(): void
    {
        $beta   = $this->project . '/tests/BetaTest.php';
        $source = (string) file_get_contents($beta);
        file_put_contents($beta, str_replace('alpha_greeting()', 'ALPHA_GREETING()', $source));

        [$sequentialExit, , $sequential]    = $this->crucible([]);
        [$parallelExit, $stdout, $parallel] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $sequentialExit);
        self::assertSame(0, $parallelExit, $stdout);
        self::assertSame($this->ranIds($sequential), $this->ranIds($parallel));
    }

    public function testCoupledWorkersPreserveFileOrderAndTheSelectedSuites(): void
    {
        file_put_contents($this->project . '/tests/AlphaTest.php', <<<'PHP'
            <?php

            use LucianoPereira\Crucible\Framework\TestCase;

            $GLOBALS['orderMarker'] = 'alpha';

            final class AlphaTest extends TestCase
            {
                public function testAlphaReadsBeta(): void
                {
                    self::assertSame('alpha', beta_saw());
                }
            }
            PHP);
        file_put_contents($this->project . '/tests/BetaTest.php', <<<'PHP'
            <?php

            use LucianoPereira\Crucible\Framework\TestCase;

            $GLOBALS['whatBetaSaw'] = $GLOBALS['orderMarker'] ?? 'missing';

            function beta_saw(): string
            {
                return $GLOBALS['whatBetaSaw'];
            }

            final class BetaTest extends TestCase
            {
                public function testBetaReadsTheSameState(): void
                {
                    self::assertSame('alpha', beta_saw());
                }
            }
            PHP);
        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('other', [], files: ['OutsideTest.php'])
                ->testSuite('unit', 'tests')
                ->build();
            PHP);
        file_put_contents($this->project . '/OutsideTest.php', <<<'PHP'
            <?php
            use LucianoPereira\Crucible\Framework\TestCase;
            final class OutsideTest extends TestCase {}
            throw new \RuntimeException('The unselected suite was loaded.');
            PHP);

        [$sequentialExit, , $sequential]    = $this->crucible(['--testsuite', 'unit']);
        [$parallelExit, $stdout, $parallel] = $this->crucible(['--parallel', '2', '--testsuite', 'unit']);

        self::assertSame(0, $sequentialExit);
        self::assertSame(0, $parallelExit, $stdout);
        self::assertCount(2, $this->ranIds($parallel));
        self::assertSame($this->ranIds($sequential), $this->ranIds($parallel));
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
