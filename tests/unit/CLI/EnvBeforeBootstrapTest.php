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
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Runner\ProcessSetup;

use function chmod;
use function dirname;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function glob;
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
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * A project's bootstrap boots its application from APP_ENV: the value the
 * configuration sets must be there before the bootstrap runs, in the parent
 * and in every worker (F-02). The bootstrap records what it saw, so the
 * assertion is on the bootstrap's own view, not on the test's.
 */
#[CoversClass(ProcessSetup::class)]
final class EnvBeforeBootstrapTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-env-bootstrap-' . uniqid();

        mkdir($root . '/tests', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testTheBootstrapSeesTheConfiguredEnvironment(): void
    {
        $this->project();
        $this->writeTest('EnvProbeTest');
        $this->clearBootstrapLogs();

        $exit = $this->crucible([]);

        self::assertSame(0, $exit, 'The serial run failed.');
        $this->assertBootstrapObservations('serial', 1);
    }

    public function testEveryWorkerSeesItToo(): void
    {
        $this->project();
        $this->writeTest('EnvProbeOneTest');
        $this->writeTest('EnvProbeTwoTest');
        $this->clearBootstrapLogs();

        $exit = $this->crucible(['--parallel', '2']);

        self::assertSame(0, $exit, 'The parallel run failed.');
        $this->assertBootstrapObservations('parallel', 3);
    }

    /**
     * The parent and each worker write one log; every one must show the
     * configured env, ini value and constant, and the test that ran in that
     * process passed because it saw them too.
     */
    private function assertBootstrapObservations(string $mode, int $expectedLogs): void
    {
        $logs = $this->bootstrapLogs();

        self::assertCount($expectedLogs, $logs, 'Unexpected number of bootstrap runs (' . $mode . ').');

        foreach ($logs as $log) {
            $observed = json_decode((string) file_get_contents($log), true);

            self::assertIsArray($observed, 'The bootstrap wrote no observation: ' . $log);
            self::assertSame('test', $observed['app_env'] ?? null, 'The bootstrap saw no APP_ENV: ' . $log);
            self::assertSame('test', $observed['env_array'] ?? null, '$_ENV lacked APP_ENV: ' . $log);
            self::assertSame('text/x-crucible-cli', $observed['ini'] ?? null, 'The bootstrap saw the old ini: ' . $log);
            self::assertSame('on', $observed['constant'] ?? null, 'The bootstrap saw no constant: ' . $log);
        }
    }

    private function project(): void
    {
        file_put_contents($this->project . '/crucible.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->bootstrap('bootstrap.php')
                ->env('APP_ENV', 'test')
                ->ini('default_mimetype', 'text/x-crucible-cli')
                ->constant('CRUCIBLE_CLI_FLAG', 'on')
                ->testSuite('unit', 'tests');
            PHP);

        file_put_contents($this->project . '/bootstrap.php', <<<'PHP'
            <?php

            file_put_contents(__DIR__ . '/boot-' . getmypid() . '.json', json_encode([
                'app_env'   => getenv('APP_ENV'),
                'env_array' => $_ENV['APP_ENV'] ?? null,
                'ini'       => ini_get('default_mimetype'),
                'constant'  => defined('CRUCIBLE_CLI_FLAG') ? constant('CRUCIBLE_CLI_FLAG') : null,
            ]));
            PHP);
    }

    private function writeTest(string $class): void
    {
        file_put_contents($this->project . '/tests/' . $class . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\\Crucible\\Framework\\TestCase;

            final class {$class} extends TestCase
            {
                public function testTheRunInheritsTheConfiguration(): void
                {
                    self::assertSame('test', getenv('APP_ENV'));
                    self::assertSame('test', \$_ENV['APP_ENV'] ?? null);
                    self::assertSame('text/x-crucible-cli', ini_get('default_mimetype'));
                    self::assertSame('on', constant('CRUCIBLE_CLI_FLAG'));
                }
            }
            PHP);
    }

    /**
     * @return list<string>
     */
    private function bootstrapLogs(): array
    {
        $logs = glob($this->project . '/boot-*.json');

        if ($logs === false) {
            return [];
        }

        sort($logs);

        return $logs;
    }

    private function clearBootstrapLogs(): void
    {
        $logs = glob($this->project . '/boot-*.json');

        if ($logs === false) {
            return;
        }

        foreach ($logs as $path) {
            @unlink($path);
        }
    }

    /**
     * @param list<string> $arguments
     */
    private function crucible(array $arguments): int
    {
        // A clean environment: APP_ENV must come from the configuration,
        // not from whatever this machine exports.
        $environment = [...getenv()];
        unset($environment['APP_ENV']);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...$environment, 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit = proc_close($process);

        if ($exit !== 0) {
            self::fail('crucible exited ' . $exit . '. stdout: ' . $stdout . ' stderr: ' . $stderr);
        }

        return $exit;
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
