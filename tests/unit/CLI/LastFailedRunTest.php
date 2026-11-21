<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Framework\TestCase;

use function array_merge;
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
 * `--last-failed`: the PHP half reads the result cache's most recent
 * outcome per test; the Vitest half gets Vitest's own flag (pinned in
 * VitestRunnerTest). A cache with no failed test in the selection runs
 * the full selection, as Vitest does.
 */
final class LastFailedRunTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-last-failed-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests')->build();
            PHP);

        file_put_contents($this->project . '/tests/FailingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class FailingTest extends TestCase
            {
                public function testFails(): void
                {
                    self::assertTrue(false);
                }
            }
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

    public function testItRunsOnlyTheTestsThatFailedInTheLastRecordedRun(): void
    {
        [$exit, $events] = $this->crucible();
        self::assertSame(1, $exit);
        self::assertSame(
            ['tests/FailingTest.php::testFails', 'tests/PassingTest.php::testPasses'],
            $this->ranIds($events),
        );

        [$exit, $events] = $this->crucible('--last-failed');
        self::assertSame(1, $exit);
        self::assertSame(['tests/FailingTest.php::testFails'], $this->ranIds($events));
    }

    public function testItRunsTheFullSelectionWhenTheLastRunHadNoFailures(): void
    {
        [$exit] = $this->crucible();
        self::assertSame(1, $exit);

        // Repair the failing test and record a clean run.
        file_put_contents($this->project . '/tests/FailingTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class FailingTest extends TestCase
            {
                public function testFails(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        [$exit] = $this->crucible();
        self::assertSame(0, $exit);

        [$exit, $events, $stdout] = $this->crucible('--last-failed');
        self::assertSame(0, $exit);
        self::assertSame(
            ['tests/FailingTest.php::testFails', 'tests/PassingTest.php::testPasses'],
            $this->ranIds($events),
        );
        self::assertStringContainsString('No failed tests in the last recorded run', $stdout);
    }

    /**
     * @return array{int, list<string>, string}
     */
    private function crucible(string ...$arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $process = proc_open(
            array_merge(
                [PHP_BINARY, dirname(__DIR__, 3) . '/crucible'],
                array_values($arguments),
                ['--log-events-json', $events],
            ),
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

        return [$exit, $lines, $stdout];
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

        return $ids;
    }

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
