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
 * A test that blocks forever used to hang the whole run: the
 * supervisor's wait is a poll interval, not a deadline. The watchdog
 * kills the unit and reports every test it never finished, so CI gets
 * a verdict.
 */
final class WorkerWatchdogTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-watchdog-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->watchdog(2)
                ->testSuite('unit', 'tests')
                ->build();
            PHP);

        file_put_contents($this->project . '/tests/HangingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class HangingTest extends TestCase
            {
                public function testHangs(): void
                {
                    sleep(60);
                }

                public function testNeverRuns(): void
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

    public function testAHungUnitIsKilledAndItsUnfinishedTestsErrored(): void
    {
        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(2, $exit, 'A hang must not be a green or a silent run.');
        self::assertStringContainsString(
            'watchdog',
            $this->errorReason($events, 'tests/HangingTest.php::testHangs'),
        );

        // Both tests were planned in one unit; the unit never reported
        // either, so both are the watchdog's verdict, not a lost result.
        self::assertStringContainsString(
            'watchdog',
            $this->errorReason($events, 'tests/HangingTest.php::testNeverRuns'),
        );
        self::assertFalse($this->completed($events));
    }

    public function testTheWatchdogMeasuresInactivityNotTotalUnitTime(): void
    {
        // Two tests, each quiet for 2.5 s, in a unit whose total is 5 s.
        // A watchdog of 4 s must let both finish: it measures the gap
        // between reports, not the unit's birthday.
        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->watchdog(4)
                ->testSuite('unit', 'tests')
                ->build();
            PHP);

        unlink($this->project . '/tests/HangingTest.php');

        file_put_contents($this->project . '/tests/SlowTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class SlowTest extends TestCase
            {
                public function testFirst(): void
                {
                    usleep(2_500_000);
                    self::assertTrue(true);
                }

                public function testSecond(): void
                {
                    usleep(2_500_000);
                    self::assertTrue(true);
                }
            }
            PHP);

        [$exit, $events] = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit);
        self::assertSame('pass', $this->outcomeOf($events, 'tests/SlowTest.php::testFirst'));
        self::assertSame('pass', $this->outcomeOf($events, 'tests/SlowTest.php::testSecond'));
        self::assertTrue($this->completed($events), 'Both tests reported within the inactivity window.');
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
     */
    private function errorReason(array $events, string $id): string
    {
        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded) || ($decoded['event'] ?? null) !== 'test:finish' || ($decoded['id'] ?? null) !== $id) {
                continue;
            }

            return is_string($decoded['reason'] ?? null) ? $decoded['reason'] : '';
        }

        self::fail('No result was reported for ' . $id);
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

        /** @var list<non-empty-string> $lines */
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
