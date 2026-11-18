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

use function array_filter;
use function array_values;
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
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * The supervisor's accounting of a worker's results (D-022, D-071): the
 * unit names the ids (occurrences included) that must come back, so a
 * worker that says run:finish without them is either cancelled by an
 * explicit stop-on or hiding a lost result — never silently green.
 */
final class WorkerAccountingTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-worker-accounting-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testAResultLostToRawStdoutIsReportedInsteadOfPassingSilently(): void
    {
        $this->project(false);
        // Raw stdout is not buffered with the test's echo output, and
        // without a newline it corrupts the next NDJSON frame — here,
        // the frame carrying the failure.
        $this->writeTest('PollutingTest', <<<'PHP'
            public function testPollutes(): void
            {
                fwrite(STDOUT, 'raw output with no newline');
                self::assertSame(1, 2);
            }

            public function testClean(): void
            {
                self::assertTrue(true);
            }
            PHP);

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertNotSame(0, $exit, 'A lost failure must not leave the run green.');
        self::assertSame('error', $this->outcomeOf($events, 'tests/PollutingTest.php::testPollutes'));
        self::assertFalse($this->completed($events));
    }

    public function testAnAcknowledgedStopOnCancellationIsNotReportedAsLost(): void
    {
        $this->project(true);
        $this->writeTest('StoppingTest', <<<'PHP'
            public function testFirst(): void
            {
                self::assertTrue(true);
            }

            public function testStops(): void
            {
                self::assertSame(1, 2);
            }

            public function testNeverRuns(): void
            {
                self::assertTrue(true);
            }
            PHP);

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(1, $exit);
        self::assertNull($this->outcomeOf($events, 'tests/StoppingTest.php::testNeverRuns'), 'The cancelled test was invented.');
        self::assertSame([], $this->errorIds($events), 'The cancelled test was reported as a lost result.');
        self::assertFalse($this->completed($events), 'An early stop is not a complete run.');
    }

    public function testAProviderIdTheWorkerCouldNotReproduceFailsTheRun(): void
    {
        $this->project(false);
        // The provider names its row after the process, so parent and
        // worker cannot agree on the id: an honest run cannot be green.
        $this->writeTest('ProviderDriftTest', <<<'PHP'
            public static function rows(): array
            {
                return ['row in ' . getmypid() => [1]];
            }

            #[\LucianoPereira\Crucible\Attributes\DataProvider('rows')]
            public function testRow(int $value): void
            {
                self::assertSame(1, $value);
            }
            PHP);

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertNotSame(0, $exit, 'A parent/worker id disagreement must not pass.');
        self::assertFalse($this->completed($events));
    }

    public function testAPlainParallelRunRemainsGreenAndComplete(): void
    {
        $this->project(false);
        $this->writeTest('PlainTest', <<<'PHP'
            public function testOne(): void
            {
                self::assertTrue(true);
            }

            public function testTwo(): void
            {
                self::assertTrue(true);
            }
            PHP);

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit);
        self::assertSame([], $this->errorIds($events));
        self::assertTrue($this->completed($events));
    }

    private function project(bool $stopOnFailure): void
    {
        $stop = $stopOnFailure ? "->stopOnFailure()" : '';

        file_put_contents($this->project . '/crucible.php', <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\\Crucible\\Configuration\\Crucible;

            return Crucible::configure(){$stop}->testSuite('unit', 'tests')->build();
            PHP);
    }

    private function writeTest(string $class, string $body): void
    {
        file_put_contents($this->project . '/tests/' . $class . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\\Crucible\\Framework\\TestCase;

            final class {$class} extends TestCase
            {
            {$body}
            }
            PHP);
    }

    /**
     * @param list<string> $events
     */
    private function outcomeOf(array $events, string $id): ?string
    {
        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && ($decoded['id'] ?? null) === $id) {
                return is_string($decoded['outcome'] ?? null) ? $decoded['outcome'] : null;
            }
        }

        return null;
    }

    /**
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function errorIds(array $events): array
    {
        $ids = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish'
                && ($decoded['outcome'] ?? null) === 'error'
                && is_string($decoded['id'] ?? null)
            ) {
                $ids[] = $decoded['id'];
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $events
     */
    private function completed(array $events): bool
    {
        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'run:finish') {
                return ($decoded['complete'] ?? false) === true;
            }
        }

        self::fail('The run produced no run:finish event.');
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, list<string>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

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

        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn(string $line): bool => $line !== '',
        ));

        return [$exit, $lines];
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
