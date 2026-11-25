<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\Laravel;

use LucianoPereira\Crucible\Framework\TestCase;

use function dirname;
use function explode;
use function fclose;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function proc_close;
use function proc_open;
use function simplexml_load_file;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

/**
 * Laravel's own TestCase, unmodified, through Crucible's migration mode.
 *
 * The oracle is a real Laravel application with real PHPUnit; the same
 * probe runs through both binaries. Nothing in vendor/ is edited — the
 * aliases are runtime names, and the project-level config comes from
 * `migrate-config`.
 */
final class LaravelMigrationTest extends TestCase
{
    private const string PROBE = 'tests/Feature/CrucibleMigrationProbeTest.php';

    public function testLaravelMigrationMatchesPhpUnit(): void
    {
        $root   = dirname(__DIR__, 4);
        $oracle = $root . '/livewire-oracle';

        if (!is_file($oracle . '/vendor/bin/phpunit') || !is_file($oracle . '/vendor/bin/crucible')) {
            self::markTestSkipped('The Laravel oracle is not installed with Crucible, so migration parity cannot be exercised.');
        }

        if (!is_file($oracle . '/crucible.php')) {
            [$exit, $output] = $this->run([PHP_BINARY, $oracle . '/vendor/bin/crucible', 'migrate-config'], $oracle);
            self::assertSame(0, $exit, 'The Laravel oracle could not migrate its phpunit.xml: ' . $output);
        }

        $probe = $oracle . '/' . self::PROBE;

        file_put_contents($probe, <<<'PHP'
            <?php

            namespace Tests\Feature;

            use Illuminate\Foundation\Testing\RefreshDatabase;
            use Illuminate\Support\Facades\DB;
            use Tests\TestCase;

            class CrucibleMigrationProbeTest extends TestCase
            {
                use RefreshDatabase;

                public function test_the_application_returns_a_successful_response(): void
                {
                    $this->get('/')->assertOk();
                }

                public function test_the_container_and_facade_are_live(): void
                {
                    self::assertTrue(app()->bound('config'));
                    self::assertSame('testing', config('app.env'));
                    self::assertSame('sqlite', DB::connection()->getDriverName());
                }

                public function test_the_database_transaction_is_real(): void
                {
                    DB::table('users')->insert(['name' => 'Probe', 'email' => 'probe@example.test', 'password' => 'x']);
                    self::assertSame(1, DB::table('users')->count());
                }
            }
            PHP);

        $stamp  = sys_get_temp_dir() . '/crucible-laravel-' . uniqid();
        $junit  = $stamp . '-junit.xml';
        $events = $stamp . '-events.ndjson';

        try {
            [$oracleExit, $oracleOutput] = $this->run(
                [PHP_BINARY, $oracle . '/vendor/bin/phpunit', '--filter', 'CrucibleMigrationProbeTest', '--colors=never', '--log-junit', $junit],
                $oracle,
            );

            self::assertSame(0, $oracleExit, 'The real PHPUnit oracle failed: ' . $oracleOutput);

            [$crucibleExit, $crucibleOutput] = $this->run(
                [PHP_BINARY, $oracle . '/vendor/bin/crucible', '--filter', 'CrucibleMigrationProbeTest', '--no-progress', '--log-events-json', $events],
                $oracle,
            );

            self::assertSame(0, $crucibleExit, 'Crucible failed the Laravel probe: ' . $crucibleOutput);

            $xml = simplexml_load_file($junit);

            self::assertNotFalse($xml, 'PHPUnit produced no JUnit report.');

            $cases    = $xml->xpath('//testcase') ?? [];
            $failures = $xml->xpath('//failure | //error') ?? [];

            self::assertCount(3, $cases);
            self::assertCount(0, $failures, 'The PHPUnit oracle did not pass every probe.');

            $finished = $this->crucibleOutcomes((string) file_get_contents($events));

            self::assertCount(3, $finished, 'Crucible did not report every Laravel probe.');
            self::assertSame(['pass', 'pass', 'pass'], $finished);
        } finally {
            @unlink($probe);
            @unlink($junit);
            @unlink($events);
        }
    }

    /**
     * @return list<string>
     */
    private function crucibleOutcomes(string $events): array
    {
        $outcomes = [];

        foreach (explode("\n", $events) as $line) {
            $decoded = $line !== '' ? json_decode($line, true) : null;

            if (is_array($decoded) && ($decoded['event'] ?? null) === 'test:finish' && is_string($decoded['outcome'] ?? null)) {
                $outcomes[] = $decoded['outcome'];
            }
        }

        return $outcomes;
    }

    /**
     * @param list<string> $command
     *
     * @return array{int, string}
     */
    private function run(array $command, string $cwd): array
    {
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            [...getenv(), 'NO_COLOR' => '1', 'TERM' => 'dumb'],
        );

        if (!is_resource($process)) {
            self::fail('Could not start ' . $command[0]);
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout . $stderr];
    }
}
