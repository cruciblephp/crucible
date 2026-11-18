<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Extension\AroundEachTestClosure;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\TestRunner;

use function chmod;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function glob;
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
 * The configuration-level per-test hook, end to end: `->aroundEachTest()`
 * brackets every test in the process that runs it — the parent here, a
 * `--parallel` worker there — and its cleanup still runs when setUp()
 * throws, which is what dama/doctrine-test-bundle needs from Crucible
 * and what a `#[Before]`/`#[After]` trait could not promise, because
 * the after phase is skipped when setUp() fails (F-04, ticket #2 item
 * 4). The hook records its bracket, so the assertion reads the hook's
 * own view of the run.
 */
#[CoversClass(AroundEachTestClosure::class)]
#[CoversClass(TestRunner::class)]
final class AroundEachTestCliTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-around-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testTheHookBracketsEveryTestInTheProcessThatRunsIt(): void
    {
        $this->project();

        // The setUp() of ProbeTwoTest throws, so the run reports one
        // error — which exits 2, errors being the graver code — and
        // the hook's finally still ran for that test.
        [$exit, $stdout] = $this->crucible([]);

        self::assertSame(2, $exit, 'The run had one error, so it exits 2: ' . $stdout);
        self::assertStringContainsString('Errors: 1', $stdout);

        $this->assertBracketed('tests/ProbeOneTest.php::testOne');
        $this->assertBracketed('tests/ProbeOneTest.php::testTwo');
        $this->assertBracketed('tests/ProbeTwoTest.php::testSetUpThrows');
    }

    public function testEveryWorkerBracketsItsOwnTests(): void
    {
        $this->project();

        // Under --parallel the parent may run some tests itself and the
        // workers the rest; whichever process ran a test, that process's
        // hook bracketed it — the transaction opens where the test runs.
        [$exit, $stdout] = $this->crucible(['--parallel', '2']);

        self::assertSame(2, $exit, 'The run had one error, so it exits 2: ' . $stdout);

        $this->assertBracketed('tests/ProbeOneTest.php::testOne');
        $this->assertBracketed('tests/ProbeOneTest.php::testTwo');
        $this->assertBracketed('tests/ProbeTwoTest.php::testSetUpThrows');
    }

    public function testNoExtensionsSilencesTheHookEverywhere(): void
    {
        $this->project();

        [$exit] = $this->crucible(['--no-extensions']);

        self::assertSame(2, $exit);

        $bracketed = $this->logText();

        self::assertStringNotContainsString('begin|', $bracketed, '--no-extensions registered no hook, serial or parallel.');
    }

    /**
     * Every process's hook log, concatenated: what the run bracketed,
     * wherever it ran.
     */
    private function logText(): string
    {
        $text = '';

        foreach ((array) glob($this->project . '/around-*.jsonl') as $log) {
            if (!is_string($log)) {
                continue;
            }

            $text .= (string) file_get_contents($log);
        }

        return $text;
    }

    private function assertBracketed(string $id): void
    {
        $text = $this->logText();

        self::assertStringContainsString('begin|' . $id . "\n", $text, 'No process opened a bracket for ' . $id . '.');
        self::assertStringContainsString('end|' . $id . "\n", $text, 'No process closed its bracket for ' . $id . ' — a throwing setUp() must still be cleaned up.');
    }

    private function project(): void
    {
        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;
            use LucianoPereira\Crucible\Test\TestId;

            return Crucible::configure()
                ->testSuite('unit', 'tests')
                ->aroundEachTest(function (TestId $id, callable $test): mixed {
                    $log = __DIR__ . '/around-' . getmypid() . '.jsonl';

                    file_put_contents($log, 'begin|' . $id->toString() . "\n", FILE_APPEND | LOCK_EX);

                    try {
                        return $test();
                    } finally {
                        file_put_contents($log, 'end|' . $id->toString() . "\n", FILE_APPEND | LOCK_EX);
                    }
                });
            PHP);

        file_put_contents($this->project . '/tests/ProbeOneTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class ProbeOneTest extends TestCase
            {
                public function testOne(): void
                {
                    self::assertTrue(true);
                }

                public function testTwo(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        file_put_contents($this->project . '/tests/ProbeTwoTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;
            use RuntimeException;

            final class ProbeTwoTest extends TestCase
            {
                protected function setUp(): void
                {
                    throw new RuntimeException('setUp refused to start');
                }

                public function testSetUpThrows(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function crucible(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        return [$exit, $stdout];
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
