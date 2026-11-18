<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\CLI;

use LucianoPereira\Crucible\Attributes\RequiresOperatingSystem;
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
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
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
use function symlink;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * The active configuration and bootstrap are environment files for
 * impact selection (F-21): editing one must run the full suite, because
 * what they define is what discovery and the run read.
 *
 * The comparison used to break for an explicit relative
 * `--configuration`, which Loader returns exactly as typed, so the
 * active file never matched the canonical change-set path. These cases
 * pin auto-discovery, relative and absolute CLI paths, a non-active
 * configuration as the control, and the bootstrap's three forms. A path
 * that cannot be resolved widens rather than vanishing from the
 * comparison.
 */
final class EnvironmentPathImpactTest extends TestCase
{
    private const array BOTH = [
        'tests/Unit/AlphaTest.php::testAlpha',
        'tests/Unit/BetaTest.php::testBeta',
    ];

    /** @var non-empty-string */
    private string $project = '.';

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/crucible-environment-paths-' . uniqid();

        mkdir($root . '/config', 0o777, true);
        mkdir($root . '/tests/Unit', 0o777, true);

        $real          = realpath($root);
        $this->project = $real === false ? $root : $real;

        $configuration = <<<'PHP'
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Configuration\Crucible;

            return Crucible::configure()
                ->testSuite('unit', 'tests/Unit')
                ->bootstrap('bootstrap.php')
                ->build();
            PHP;

        file_put_contents($this->project . '/crucible.php', $configuration);
        file_put_contents($this->project . '/config/crucible.php', $configuration);
        file_put_contents($this->project . '/config/other.php', $configuration);
        file_put_contents($this->project . '/bootstrap.php', "<?php\n\n// the project bootstrap\n");

        file_put_contents($this->project . '/tests/Unit/AlphaTest.php', $this->test('AlphaTest', 'testAlpha'));
        file_put_contents($this->project . '/tests/Unit/BetaTest.php', $this->test('BetaTest', 'testBeta'));

        file_put_contents($this->project . '/.gitignore', ".crucible.cache/\nevents.ndjson\n");

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

    public function testAnAutoDiscoveredConfigurationEditWidensTheSelection(): void
    {
        $this->edit('crucible.php');

        [$exit, $stdout, $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished);
    }

    public function testARelativeConfigurationOptionEditWidensTheSelection(): void
    {
        $this->edit('config/crucible.php');

        [$exit, $stdout, $finished] = $this->crucible(['--dirty', '--configuration', 'config/crucible.php']);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished);
    }

    public function testAnAbsoluteConfigurationOptionEditWidensTheSelection(): void
    {
        $this->edit('config/crucible.php');

        [$exit, $stdout, $finished] = $this->crucible([
            '--dirty',
            '--configuration',
            $this->project . '/config/crucible.php',
        ]);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished);
    }

    public function testADifferentConfigurationFileDoesNotWidenTheSelection(): void
    {
        $this->edit('config/other.php');

        [$exit, $stdout, $finished] = $this->crucible(['--dirty', '--configuration', 'config/crucible.php']);

        self::assertSame(0, $exit);
        self::assertSame([], array_column($finished, 'id'), 'A configuration nobody loaded selected tests.');
        self::assertStringContainsString('No tests are affected', $stdout);
    }

    public function testAConfiguredBootstrapEditWidensTheSelection(): void
    {
        $this->edit('bootstrap.php');

        [$exit, $stdout, $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished);
    }

    public function testARelativeBootstrapOptionEditWidensTheSelection(): void
    {
        $this->edit('bootstrap.php');

        [$exit, $stdout, $finished] = $this->crucible(['--dirty', '--bootstrap', 'bootstrap.php']);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished);
    }

    public function testAnAbsoluteBootstrapOptionEditWidensTheSelection(): void
    {
        $this->edit('bootstrap.php');

        [$exit, $stdout, $finished] = $this->crucible([
            '--dirty',
            '--bootstrap',
            $this->project . '/bootstrap.php',
        ]);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished);
    }

    #[RequiresOperatingSystem('^(?!WIN)')]
    public function testAnUnresolvableBootstrapWidensTheSelection(): void
    {
        unlink($this->project . '/bootstrap.php');
        symlink('missing-target.php', $this->project . '/bootstrap.php');

        [$exit, $stdout, $finished] = $this->crucible(['--dirty']);

        self::assertSame(0, $exit);
        $this->assertWidened($stdout, $finished, 'cannot be resolved');
    }

    /**
     * @param list<array{id: string, outcome: string}> $finished
     */
    private function assertWidened(string $stdout, array $finished, string $reason = 'defines the environment'): void
    {
        self::assertSame(self::BOTH, array_column($finished, 'id'), 'The environment edit did not run the full suite.');
        self::assertStringContainsString($reason, $stdout);
    }

    private function edit(string $file): void
    {
        file_put_contents(
            $this->project . '/' . $file,
            (string) file_get_contents($this->project . '/' . $file) . "\n// edited\n",
        );
    }

    private function test(string $class, string $method): string
    {
        return <<<PHP
            <?php

            declare(strict_types=1);

            use LucianoPereira\Crucible\Framework\TestCase;

            final class {$class} extends TestCase
            {
                public function {$method}(): void
                {
                    self::assertTrue(true);
                }
            }
            PHP;
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{int, string, list<array{id: string, outcome: string}>}
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
            if (is_link($path)) {
                unlink($path);

                return;
            }

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
