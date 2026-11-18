<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Runner;

use LucianoPereira\Crucible\Framework\TestCase;

use function array_merge;
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
 * A test that breaks the error-handler stack is risky, not silent.
 *
 * The incumbent reports a window that left handlers behind as a risky
 * verdict with its own wording ("Test code or tested code did not
 * remove its own error handlers"), and one that removed handlers other
 * than its own the same way. The runner now brackets the handler
 * STACK, not just its own frame, so the same tests it would silently
 * misattribute — the leak keeps the collector live past its window
 * and the next test's issues start with someone else's errors — are
 * named on the report instead.
 */
final class ErrorHandlerHygieneCliTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-handler-hygiene-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()->testSuite('unit', 'tests')->build();
            PHP);

        file_put_contents($this->project . '/tests/LeakyTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class LeakyTest extends TestCase
            {
                public function testPassesButLeavesAHandlerInstalled(): void
                {
                    set_error_handler(static function (int $level, string $message): bool {
                        return true;
                    });

                    self::assertTrue(true);
                }

                public function testRemovesAHandlerItDidNotInstall(): void
                {
                    restore_error_handler();

                    self::assertTrue(true);
                }

                public function testRunsCleanAfterBoth(): void
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

    public function testAWindowThatDisturbsTheStackIsRiskyNotSilent(): void
    {
        [$exit, $stdout, $events] = $this->crucible();

        // The two disturbed windows are risky with the incumbent's
        // wording; the third test must still run clean, which also
        // says the run kept going on a stack the first two bent.
        $outcomes = $this->outcomes($events);

        self::assertSame(
            'risky',
            $outcomes['tests/LeakyTest.php::testPassesButLeavesAHandlerInstalled']['outcome'] ?? null,
            'A leaked handler must be reported, not silently popped by the drain. stdout: ' . $stdout,
        );
        self::assertSame(
            'Test code or tested code did not remove its own error handlers.',
            $outcomes['tests/LeakyTest.php::testPassesButLeavesAHandlerInstalled']['reason'] ?? null,
        );

        self::assertSame(
            'risky',
            $outcomes['tests/LeakyTest.php::testRemovesAHandlerItDidNotInstall']['outcome'] ?? null,
            'A removed foreign handler must be reported the same way.',
        );
        self::assertSame(
            'Test code or tested code removed error handlers other than its own.',
            $outcomes['tests/LeakyTest.php::testRemovesAHandlerItDidNotInstall']['reason'] ?? null,
        );

        self::assertSame(
            'pass',
            $outcomes['tests/LeakyTest.php::testRunsCleanAfterBoth']['outcome'] ?? null,
            'The window after a disturbed one must still run and pass clean: no misattributed issues.',
        );

        self::assertStringContainsString('Risky: 2', $stdout);
        self::assertSame(0, $exit, 'Risky verdicts do not fail a run unless the policy says so.');
    }

    public function testFailOnRiskyMakesTheSameRunExitNonZero(): void
    {
        [$exit, $stdout] = $this->crucible(['--fail-on-risky']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('Risky: 2', $stdout);
    }

    /**
     * @param list<string> $events
     *
     * @return array<string, array{outcome: string, reason: ?string}>
     */
    private function outcomes(array $events): array
    {
        $outcomes = [];

        foreach ($events as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $outcomes[$decoded['id']] = [
                    'outcome' => is_string($decoded['outcome'] ?? null) ? $decoded['outcome'] : '',
                    'reason'  => is_string($decoded['reason'] ?? null) ? $decoded['reason'] : null,
                ];
            }
        }

        return $outcomes;
    }

    /**
     * @param list<string> $extraArguments
     *
     * @return array{int, string, list<string>}
     */
    private function crucible(array $extraArguments = []): array
    {
        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $command = array_merge(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible'],
            $extraArguments,
            ['--log-events-json', $events],
        );

        $process = proc_open(
            $command,
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
