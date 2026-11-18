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
 * A worker's discovery is bounded by its own work.
 *
 * Every worker used to rediscover the entire configured suite before
 * filtering down to the single file its unit names: N-1 other test
 * files loaded, their data providers executed, their top-level side
 * effects repeated, per worker, per unit. On a three-file suite with
 * three workers that is four loads of each file where two answer the
 * same question; the plan cost multiplied by the worker count.
 *
 * The counter here is the honest one: every test file appends to a log
 * when it is required, every provider appends when it runs, and the
 * number of lines says who loaded what. The ids and verdicts must stay
 * identical to the sequential run - incrementality may skip work,
 * never change the answer.
 */
final class WorkerUnitDiscoveryTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-discovery-' . uniqid();

        mkdir($root . '/tests', 0o777, true);
        mkdir($root . '/src', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('unit', 'tests')
                ->source(include: ['src'])
                ->build();
            PHP);

        // PHPUnit dialect, with a data provider that counts its own
        // executions: providers run during discovery, so their cost
        // multiplies with every redundant pass.
        file_put_contents($this->project . '/tests/AlphaTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Attributes\DataProvider;
            use LucianoPereira\Crucible\Framework\TestCase;

            file_put_contents(__DIR__ . '/../counters.log', "load alpha\n", FILE_APPEND);

            final class AlphaTest extends TestCase
            {
                public static function rows(): array
                {
                    file_put_contents(__DIR__ . '/../counters.log', "provider alpha\n", FILE_APPEND);

                    return [
                        'row one' => ['one'],
                        'row two' => ['two'],
                    ];
                }

                #[DataProvider('rows')]
                public function testRows(string $row): void
                {
                    self::assertNotSame('', $row);
                }
            }
            PHP);

        // A plain *Test.php written in Pest's vocabulary: routed by
        // content, so a worker must load it to know what it carries.
        file_put_contents($this->project . '/tests/BetaTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            file_put_contents(__DIR__ . '/../counters.log', "load beta\n", FILE_APPEND);

            \test('beta knows its name', function (): void {
                \expect('beta')->toBe('beta');
            });
            PHP);

        // The crucible dialect, carried by its own suffix.
        file_put_contents($this->project . '/tests/Gamma.crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            file_put_contents(__DIR__ . '/../counters.log', "load gamma\n", FILE_APPEND);

            \check(fn(): string => 'gamma')->toBe('gamma');
            PHP);

        // An inline carrier in application source: the one route a
        // positional path never takes (D-152), so the worker's rebuild
        // has to keep reaching it by name.
        file_put_contents($this->project . '/src/Calculator.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            file_put_contents(__DIR__ . '/../counters.log', "load calculator\n", FILE_APPEND);

            final class Calculator
            {
                /**
                 * @crucible expect(\Calculator::sum(2, 3))->toBe(5)
                 */
                public static function sum(int $a, int $b): int
                {
                    return $a + $b;
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
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
     * @return array{int, int, int, int, int}
     */
    private function counters(): array
    {
        $log    = (string) file_get_contents($this->project . '/counters.log');
        $counts = [
            'alpha'      => substr_count($log, 'load alpha'),
            'beta'       => substr_count($log, 'load beta'),
            'gamma'      => substr_count($log, 'load gamma'),
            'calculator' => substr_count($log, 'load calculator'),
            'provider'   => substr_count($log, 'provider alpha'),
        ];

        return [$counts['alpha'], $counts['beta'], $counts['gamma'], $counts['calculator'], $counts['provider']];
    }

    public function testAParallelWorkerDiscoversOnlyTheFilesItsUnitsName(): void
    {
        // The sequential baseline: one load per file, one provider
        // execution - the price of discovering the plan once.
        [$exit, , $events] = $this->crucible([]);

        self::assertSame(0, $exit);
        self::assertSame([1, 1, 1, 1, 1], $this->counters(), 'The sequential run is the discovery cost baseline.');
        $sequential = $this->ranIds($events);

        // The parallel twin: a parent pass plus one worker per file,
        // and nothing else. Before this change every worker loaded
        // every file - four loads each on this three-file suite.
        [$exit, $stdout, $events] = $this->crucible(['--parallel', '3']);

        self::assertSame(0, $exit, $stdout);
        self::assertSame([2, 2, 2, 2, 2], $this->counters(), 'Each file may be discovered exactly twice: once by the parent, once by the worker that runs it.');
        self::assertSame($sequential, $this->ranIds($events), 'A parallel run executes the sequential plan exactly.');
    }

    public function testProcessIsolationKeepsTheSameBounds(): void
    {
        // One worker per test name, one unit per file: the same two
        // loads per file, the same plan answered.
        [$exit, , $events] = $this->crucible(['--process-isolation']);

        self::assertSame(0, $exit);
        self::assertSame([2, 2, 2, 2, 2], $this->counters(), 'Isolation multiplies processes, not discovery.');
    }

    public function testRepeatRunsTheSamePlanTwiceOver(): void
    {
        [$exit, , $baseline] = $this->crucible([]);
        $expected            = [...$this->ranIds($baseline), ...$this->ranIds($baseline)];
        sort($expected);

        [$exit, , $events] = $this->crucible(['--parallel', '3', '--repeat', '2']);

        self::assertSame(0, $exit);
        self::assertSame($expected, $this->ranIds($events), 'Repetition doubles the plan, not the discovery of files no unit names.');
        self::assertSame([2, 2, 2, 2, 2], $this->counters());
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
