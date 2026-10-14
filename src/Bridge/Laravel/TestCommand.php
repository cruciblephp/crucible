<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Bridge\Laravel;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function base_path;
use function is_array;
use function is_file;
use function is_string;

use const PHP_BINARY;

/**
 * `php artisan test`, backed by Crucible. A thin translation onto the
 * crucible binary — the engine's own console output streams through
 * untouched, and the exit code is the engine's exit code. Replaces
 * the PHPUnit-coupled command a fresh Laravel app ships with (which
 * cannot run once phpunit/phpunit leaves the vendor directory).
 */
final class TestCommand extends Command
{
    /** @var string */
    protected $signature = 'test
        {--filter= : Run only tests whose name matches the pattern}
        {--testsuite= : Run only the named test suite(s)}
        {--group= : Run only tests in the given group(s)}
        {--exclude-group= : Never run tests in the given group(s)}
        {--parallel= : Run test classes in N worker processes}
        {--shard= : Run the Mth of N hash-stable suite slices (M/N)}
        {--order-by= : Run tests in order: default|defects|duration|random|reverse|size}
        {--random-order-seed= : Seed for --order-by random}
        {--stop-on-failure : Stop after the first failure}
        {--stop-on-defect : Stop after the first error, failure, or risky test}
        {--testdox : Show the documentation view}
        {--coverage : Collect line coverage and print the per-file summary}
        {--coverage-clover= : Write a Clover XML coverage report to the given file}
        {--report=* : Render registered report formats to key:path,... (see crucible extensions)}
        {--log-events-json= : Write the NDJSON event stream to the given file}
        {--log-events-text= : Write the event stream to the given file as one line per event}
        {--log-events-verbose= : As --log-events-text, keeping each event\'s payload fields}
        {--log-junit= : Write a JUnit XML report to the given file}
        {--log-markdown= : Write a Markdown report to the given file}
        {--log-otr= : Write an Open Test Reporting (opentest4j) event stream to the given file}
        {--log-pdf= : Write a PDF report to the given file}
        {--log-teamcity= : Write TeamCity service messages to the given file}
        {--reproducible : Normalise what describes the run, so two runs write identical report bytes}
        {--update-baseline : Add the run\'s deprecations to the baseline}';

    /** @var string */
    protected $description = 'Run the application tests with Crucible';

    public function handle(): int
    {
        $binary = base_path('vendor/bin/crucible');

        if (!is_file($binary)) {
            $this->error('The crucible binary was not found at vendor/bin/crucible.');

            return self::FAILURE;
        }

        $command = [PHP_BINARY, $binary];

        foreach (['filter', 'testsuite', 'group', 'exclude-group', 'parallel', 'shard', 'order-by', 'random-order-seed', 'coverage-clover', 'log-events-json', 'log-events-text', 'log-events-verbose', 'log-junit', 'log-markdown', 'log-otr', 'log-pdf', 'log-teamcity'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $command[] = '--' . $option;
                $command[] = $value;
            }
        }

        $reports = $this->option('report');

        foreach (is_array($reports) ? $reports : [] as $report) {
            if (is_string($report) && $report !== '') {
                $command[] = '--report';
                $command[] = $report;
            }
        }

        foreach (['stop-on-failure', 'stop-on-defect', 'testdox', 'coverage', 'update-baseline', 'reproducible'] as $flag) {
            if ($this->option($flag) === true) {
                $command[] = '--' . $flag;
            }
        }

        $command[] = '--colors=' . ($this->output->isDecorated() ? 'always' : 'never');

        $process = new Process($command, base_path(), timeout: null);

        return $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
    }
}
