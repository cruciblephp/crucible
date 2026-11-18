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
use function is_int;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
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
 * A run that only reached part of the suite must never claim to be
 * complete (D-071): the completeness flag is the only proof that lets
 * the property-failure database and the snapshot pruner delete stored
 * entries. `--dirty` and `--test-suffix` narrow the plan just like
 * `--changed` does, and must narrow the flag with it.
 */
final class DirtyRunCompletenessTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-dirty-run-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests')->build();
            PHP);

        file_put_contents($this->project . '/tests/KeepTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class KeepTest extends TestCase
            {
                public function testKeeps(): void
                {
                    self::assertMatchesSnapshot(['kept' => true]);
                }

                public function testAlsoKeeps(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        file_put_contents($this->project . '/tests/OmitTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class OmitTest extends TestCase
            {
                public function testOmitted(): void
                {
                    self::assertMatchesSnapshot(['omitted' => true]);
                }
            }
            PHP);

        $this->git('init -q');
        $this->git('config user.email crucible@example.test');
        $this->git('config user.name Crucible');
        $this->git('add .');
        $this->git('commit -q -m base');
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testATestSuffixPartialRunKeepsStoredArtifacts(): void
    {
        $this->recordEverySnapshot();
        $this->seedPropertyFailure();
        $omitSnapshot = $this->snapshotFile('OmitTest');

        [$exit, $events] = $this->crucible(['-u', '--test-suffix', 'KeepTest.php']);

        self::assertSame(0, $exit);
        self::assertSame(['tests/KeepTest.php::testKeeps', 'tests/KeepTest.php::testAlsoKeeps'], $this->ranIds($events));
        $this->assertPartialRunKeptItsArtifacts($events, $omitSnapshot);
    }

    public function testADirtyPartialRunKeepsStoredArtifacts(): void
    {
        $this->recordEverySnapshot();
        $this->seedPropertyFailure();
        $omitSnapshot = $this->snapshotFile('OmitTest');

        // Only the kept test changed, so --dirty selects exactly it.
        file_put_contents(
            $this->project . '/tests/KeepTest.php',
            (string) file_get_contents($this->project . '/tests/KeepTest.php') . "\n// touched\n",
        );

        [$exit, $events] = $this->crucible(['-u', '--dirty']);

        self::assertSame(0, $exit);
        self::assertSame(['tests/KeepTest.php::testKeeps', 'tests/KeepTest.php::testAlsoKeeps'], $this->ranIds($events));
        $this->assertPartialRunKeptItsArtifacts($events, $omitSnapshot);
    }

    public function testAFullRunStillPrunesObsoleteArtifacts(): void
    {
        $this->recordEverySnapshot();
        $this->seedPropertyFailure();

        // The test file is gone: its snapshot and database entry are
        // genuinely obsolete, and only a complete -u run may say so.
        unlink($this->project . '/tests/OmitTest.php');

        [$exit, $events] = $this->crucible(['-u']);

        self::assertSame(0, $exit);
        self::assertTrue($this->completed($events));
        self::assertFalse(is_file($this->snapshotFile('OmitTest')));
        self::assertSame([], $this->storedPropertyFailures());
    }

    /**
     * @param list<string> $events
     */
    private function assertPartialRunKeptItsArtifacts(array $events, string $omitSnapshot): void
    {
        self::assertFalse($this->completed($events), 'A partial run must omit complete from run:finish.');
        self::assertTrue(is_file($omitSnapshot), 'The omitted test kept its snapshot.');
        self::assertArrayHasKey(
            'tests/OmitTest.php::testOmitted#property 0',
            $this->storedPropertyFailures(),
            'The omitted test kept its property-failure entry.',
        );
    }

    /**
     * Records the initial snapshots through a full update run.
     */
    private function recordEverySnapshot(): void
    {
        [$exit, $events] = $this->crucible(['-u']);

        self::assertSame(0, $exit, 'The fixture could not record its snapshots.');
        self::assertTrue($this->completed($events));
        self::assertTrue(is_file($this->snapshotFile('OmitTest')));
    }

    private function seedPropertyFailure(): void
    {
        $file = $this->project . '/.crucible.cache/property-failures.json';

        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, json_encode([
            'version'  => 1,
            'failures' => ['tests/OmitTest.php::testOmitted#property 0' => [[1, 2]]],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, list<list<int>>>
     */
    private function storedPropertyFailures(): array
    {
        $file = $this->project . '/.crucible.cache/property-failures.json';

        if (!is_file($file)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded) || !is_array($decoded['failures'] ?? null)) {
            return [];
        }

        $failures = [];

        // The store writes ids mapped to a list of attempt coordinates; a
        // shape it never writes is a fixture bug, not a wider return type.
        foreach ($decoded['failures'] as $id => $points) {
            if (!is_string($id) || !is_array($points)) {
                continue;
            }

            $attempts = [];

            foreach ($points as $point) {
                if (!is_array($point)) {
                    continue;
                }

                $coordinates = [];

                foreach ($point as $value) {
                    if (is_int($value)) {
                        $coordinates[] = $value;
                    }
                }

                $attempts[] = $coordinates;
            }

            $failures[$id] = $attempts;
        }

        return $failures;
    }

    private function snapshotFile(string $test): string
    {
        return $this->project . '/tests/__snapshots__/' . $test . '.snap';
    }

    /**
     * The ids the stream says finished, in order.
     *
     * @param list<string> $events
     *
     * @return list<string>
     */
    private function ranIds(array $events): array
    {
        $ids = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (!is_array($decoded) || ($decoded['event'] ?? null) !== 'test:finish' || !is_string($decoded['id'] ?? null)) {
                continue;
            }

            $ids[] = $decoded['id'];
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
     * Runs the real binary in the fixture project and returns its exit
     * code plus the NDJSON lines.
     *
     * @param list<string> $arguments
     *
     * @return array{int, list<string>}
     */
    private function crucible(array $arguments): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible', ...$arguments, '--log-events-json', $events],
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

        $exit = proc_close($process);

        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        if ($contents === '') {
            self::fail('No event stream was written. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        $lines = array_values(array_filter(explode("\n", $contents), static fn(string $line): bool => $line !== ''));

        return [$exit, $lines];
    }

    private function git(string $command): void
    {
        $process = proc_open(
            ['git', '-C', $this->project, ...explode(' ', $command)],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            self::fail('git ' . $command . ' could not be started.');
        }

        $error = (string) stream_get_contents($pipes[2]);
        stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0) {
            self::fail('git ' . $command . ' failed: ' . $error);
        }
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
