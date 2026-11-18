<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_values;
use function chmod;
use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function implode;
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
use function sprintf;
use function str_ends_with;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function var_export;

use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

/**
 * The JS tier under impact selection when the environment changes
 * (CB-19, D-079/D-080): a root manifest, lockfile or workspace
 * configuration runs every suite in full; a package's own configuration
 * runs that package alone; an ordinary source change keeps `related`; a
 * shared module outside every suite root is offered to each suite's own
 * graph. Vitest is a stand-in that records its argv and answers a fixed
 * report, so the command and the verdict are asserted without a Node
 * toolchain.
 */
final class VitestEnvironmentImpactTest extends TestCase
{
    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-vitest-environment-' . uniqid();

        foreach (['tests/Unit', 'packages/app/src', 'packages/web/src', 'packages/shared/src'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        $this->suite();
    }

    protected function tearDown(): void
    {
        $this->remove($this->project);
    }

    public function testARootLockfileRunsEverySuiteInFull(): void
    {
        file_put_contents($this->project . '/package-lock.json', "{\"lockfileVersion\":3,\"packages\":{},\"x\":1}\n");

        [$exit, , $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertRanWhole('app');
        $this->assertRanWhole('web');
        self::assertContains('tests/Unit/ControlTest.php::testControl', array_column($finished, 'id'), 'The PHP tier widens on a lockfile too.');
    }

    public function testAWorkspaceConfigurationRunsBothSuitesWithNoPhpTests(): void
    {
        file_put_contents($this->project . '/vitest.workspace.ts', "export default [{ test: { name: 'all' } }];\n");

        [$exit, $stdout, $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertRanWhole('app');
        $this->assertRanWhole('web');
        self::assertNotContains('tests/Unit/ControlTest.php::testControl', array_column($finished, 'id'), 'A .ts change touches no PHP test.');
        self::assertStringContainsString('configures it, running the whole suite', $stdout);
    }

    public function testASuiteLocalConfigurationRunsOnlyThatSuite(): void
    {
        file_put_contents($this->project . '/packages/app/vitest.config.ts', "export default { test: { environment: 'node' } };\n");

        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertRanWhole('app');
        self::assertNull($this->argv('web'), 'The sibling package must not be started for another package configuration.');
    }

    public function testAnOrdinarySourceChangeKeepsRelated(): void
    {
        file_put_contents($this->project . '/packages/app/src/cart.js', "export const cart = 2;\n");

        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);

        $argv = $this->argv('app');

        self::assertNotNull($argv);
        self::assertSame('related', $argv[0]);
        self::assertTrue(
            array_filter($argv, static fn(string $argument): bool => str_ends_with(WorkingDirectory::portable($argument), 'src/cart.js')) !== [],
            'The changed module is not in vitest related: ' . implode(' ', $argv),
        );
        self::assertNull($this->argv('web'));
    }

    public function testADeletionRunsEverySuiteInFull(): void
    {
        unlink($this->project . '/packages/app/src/cart.js');

        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertRanWhole('app');
        $this->assertRanWhole('web');
    }

    public function testASharedModuleOutsideEverySuiteRootIsOfferedToBothSuites(): void
    {
        file_put_contents($this->project . '/packages/shared/src/format.js', "export const format = (value) => '#' + value;\n");

        [$exit] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);

        foreach (['app', 'web'] as $suite) {
            $argv = $this->argv($suite);

            self::assertNotNull($argv);
            self::assertSame('related', $argv[0], $suite . ' was not asked.');
            self::assertTrue(
                array_filter($argv, static fn(string $argument): bool => str_ends_with(WorkingDirectory::portable($argument), 'src/format.js')) !== [],
                'The shared module is not in ' . $suite . '\'s related set: ' . implode(' ', $argv),
            );
        }
    }

    public function testAnUnrelatedPhpEditLeavesVitestOut(): void
    {
        file_put_contents(
            $this->project . '/tests/Unit/ControlTest.php',
            (string) file_get_contents($this->project . '/tests/Unit/ControlTest.php') . "\n// edited\n",
        );

        [$exit, , $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        self::assertSame(['tests/Unit/ControlTest.php::testControl'], array_column($finished, 'id'));
        self::assertNull($this->argv('app'));
        self::assertNull($this->argv('web'));
    }

    public function testAFailingVitestVerdictFailsTheRun(): void
    {
        file_put_contents($this->project . '/vitest.workspace.ts', "export default []; // changed\n");

        [$exit, , $finished] = $this->crucible(['--dirty'], ['CRUCIBLE_TEST_VITEST_FAIL' => '1']);

        self::assertSame(1, $exit, 'A folded JS failure must fail the run.');
        self::assertContains('fail', array_column($finished, 'outcome'));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     *
     * @return array{int, string, list<array{id: string, outcome: string}>}
     */
    private function crucible(array $arguments, array $environment = []): array
    {
        foreach (['app', 'web'] as $suite) {
            @unlink($this->project . '/packages/' . $suite . '/argv.json');
        }

        $events = $this->project . '/events.ndjson';
        @unlink($events);

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/crucible', ...$arguments, '--log-events-json', $events],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->project,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb', ...$environment],
        );

        if (!is_resource($process)) {
            self::fail('crucible could not be started.');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exit     = proc_close($process);
        $contents = is_file($events) ? (string) file_get_contents($events) : '';

        /** @var list<non-empty-string> $lines */
        $lines = array_values(array_filter(
            explode("\n", $contents),
            static fn(string $line): bool => $line !== '',
        ));

        $finished = [];

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['id'] ?? null)) {
                $finished[] = [
                    'id'      => $decoded['id'],
                    'outcome' => is_string($decoded['outcome'] ?? null) ? $decoded['outcome'] : '',
                ];
            }
        }

        return [$exit, $stdout, $finished];
    }

    /** @return list<string>|null */
    private function argv(string $suite): ?array
    {
        $file = $this->project . '/packages/' . $suite . '/argv.json';

        if (!is_file($file)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);

        if (!is_array($decoded)) {
            return null;
        }

        $argv = [];

        foreach ($decoded as $argument) {
            if (is_string($argument)) {
                $argv[] = $argument;
            }
        }

        return $argv;
    }

    private function assertRanWhole(string $suite): void
    {
        $argv = $this->argv($suite);

        self::assertNotNull($argv, 'Vitest suite ' . $suite . ' was not started.');
        self::assertSame('run', $argv[0], 'Suite ' . $suite . ' was narrowed instead of run whole: ' . implode(' ', $argv));
        self::assertNotContains('related', $argv);
    }

    private function suite(): void
    {
        $binaries = [
            'app' => $this->standIn('app'),
            'web' => $this->standIn('web'),
        ];

        file_put_contents($this->project . '/crucible.php', sprintf(<<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('unit', 'tests/Unit')
                ->vitest('packages/app', binary: %s, name: 'app')
                ->vitest('packages/web', binary: %s, name: 'web')
                ->build();
            PHP, var_export($binaries['app'], true), var_export($binaries['web'], true)));

        file_put_contents($this->project . '/package.json', "{\"private\":true}\n");
        file_put_contents($this->project . '/package-lock.json', "{\"lockfileVersion\":3,\"packages\":{}}\n");
        file_put_contents($this->project . '/vitest.workspace.ts', "export default [];\n");

        file_put_contents($this->project . '/tests/Unit/ControlTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class ControlTest extends TestCase
            {
                public function testControl(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP);

        file_put_contents($this->project . '/packages/app/package.json', "{\"private\":true}\n");
        file_put_contents($this->project . '/packages/app/vitest.config.ts', "export default {};\n");
        file_put_contents($this->project . '/packages/app/src/cart.js', "export const cart = 1;\n");

        file_put_contents($this->project . '/packages/web/package.json', "{\"private\":true}\n");
        file_put_contents($this->project . '/packages/web/vitest.config.ts', "export default {};\n");
        file_put_contents($this->project . '/packages/web/src/page.js', "export const page = 1;\n");

        file_put_contents($this->project . '/packages/shared/package.json', "{\"private\":true}\n");
        file_put_contents($this->project . '/packages/shared/src/format.js', "export const format = (value) => value;\n");

        file_put_contents($this->project . '/.gitignore', ".crucible.cache/\nevents.ndjson\nargv.json\n");

        $this->git('init -q');
        $this->git('config user.email crucible@example.test');
        $this->git('config user.name Crucible');
        $this->git('add .');
        $this->git('commit -q -m base');
    }

    /**
     * A Vitest executable that records its argv and writes the JSON
     * report where the runner asks. Batches run under Windows; a `#!`
     * script elsewhere.
     */
    private function standIn(string $suite): string
    {
        $directory = $this->project . '/packages/' . $suite;
        $script    = $directory . '/standin.php';
        $source    = "<?php\n" . $this->standInBody($suite);

        file_put_contents($script, $source);

        if (DIRECTORY_SEPARATOR !== '\\') {
            $binary = $directory . '/vitest';
            file_put_contents($binary, '#!' . PHP_BINARY . "\n" . $source);
            chmod($binary, 0o755);

            return $binary;
        }

        $binary = $directory . '/vitest.bat';
        file_put_contents($binary, "@echo off\r\n\"" . PHP_BINARY . "\" \"" . $script . "\" %*\r\n");

        return $binary;
    }

    private function standInBody(string $suite): string
    {
        return <<<PHP
            file_put_contents(__DIR__ . '/argv.json', json_encode(array_slice(\$argv, 1)));
            \$failed = getenv('CRUCIBLE_TEST_VITEST_FAIL') === '1';
            \$report = json_encode([
                'testResults' => [[
                    'name' => __DIR__ . '/{$suite}.test.js',
                    'assertionResults' => [[
                        'fullName' => '{$suite} works',
                        'status' => \$failed ? 'failed' : 'passed',
                        'failureMessages' => ['AssertionError: expected 1 to be 2'],
                    ]],
                ]],
            ]);
            foreach (\$argv as \$argument) {
                if (str_starts_with(\$argument, '--outputFile=')) {
                    file_put_contents(substr(\$argument, 13), \$report);
                }
            }
            PHP;
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
