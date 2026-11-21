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
use function in_array;
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
 *
 * The signature mirrors the engine's surface rather than a curated
 * subset: every option declared here is forwarded by name, so a flag
 * added to the engine later needs only its declaration, not a second
 * forwarding branch. Laravel's own `--without-tty`, `--env` and
 * database-recreation switches are accepted and left to Laravel.
 *
 * Tokens after `--` are appended verbatim, for a new engine flag this
 * class has not learned yet.
 */
final class TestCommand extends Command
{
    /** @var string */
    protected $signature = 'test
        {--filter= : Run only tests whose name matches the pattern}
        {--testsuite=* : Run only the named test suite(s)}
        {--exclude-testsuite=* : Never run the named test suite(s)}
        {--group=* : Run only tests in the given group(s)}
        {--exclude-group=* : Never run tests in the given group(s)}
        {--covers=* : Run only tests covering the given target(s)}
        {--uses=* : Run only tests using the given target(s)}
        {--test-suffix=* : Test file suffixes to discover}
        {--parallel= : Run test classes in N worker processes}
        {--process-isolation : Run every test in its own process}
        {--shard= : Run the Mth of N hash-stable suite slices (M/N)}
        {--order-by= : Run tests in order: default|defects|duration|random|reverse|size}
        {--random-order-seed= : Seed for --order-by random}
        {--repeat= : Run the suite N times}
        {--retries= : Retry a failed test N times}
        {--last-failed : Run only what failed or errored in the last recorded run}
        {--stop-on-failure : Stop after the first failure}
        {--stop-on-defect : Stop after the first error, failure, or risky test}
        {--stop-on-skipped : Stop after the first skipped test}
        {--stop-on-incomplete : Stop after the first incomplete test}
        {--testdox : Show the documentation view}
        {--profile : List the slowest tests and their share of the run}
        {--compact : Progress and tally only}
        {--no-progress : Print no per-test progress characters}
        {--no-results : Print no problem list and no passed tree}
        {--without-tty : Accepted for Laravel compatibility; the engine does not use a TTY}
        {--env= : The environment Laravel should boot for the tests}
        {--without-databases : Accepted for Laravel compatibility; database setup stays with Laravel}
        {--recreate-databases : Accepted for Laravel compatibility; database setup stays with Laravel}
        {--drop-databases : Accepted for Laravel compatibility; database setup stays with Laravel}
        {--coverage : Collect line coverage and print the per-file summary}
        {--coverage-clover= : Write a Clover XML coverage report to the given file}
        {--coverage-html= : Write an HTML coverage report to the given directory}
        {--coverage-cobertura= : Write a Cobertura XML coverage report to the given file}
        {--coverage-xml= : Write a PHPUnit-compatible coverage XML report to the given directory}
        {--min= : Fail when coverage is below this percentage}
        {--coverage-min=* : Per-target coverage floor, Name:pct or Name1,Name2:pct}
        {--coverage-target=* : Named coverage target, Name=path}
        {--display-all-issues : List every issue kind}
        {--display-deprecations : List deprecations}
        {--display-errors : List errored tests}
        {--display-incomplete : List incomplete tests}
        {--display-notices : List notices}
        {--display-skipped : List skipped tests}
        {--display-warnings : List warnings}
        {--fail-on-deprecation : Exit non-zero on a deprecation}
        {--fail-on-direct : Exit non-zero on a direct deprecation}
        {--fail-on-indirect : Exit non-zero on an indirect deprecation}
        {--fail-on-notice : Exit non-zero on a notice}
        {--fail-on-warning : Exit non-zero on a warning}
        {--fail-on-risky : Exit non-zero on a risky test}
        {--fail-on-skipped : Exit non-zero on a skipped test}
        {--fail-on-incomplete : Exit non-zero on an incomplete test}
        {--fail-on-empty-test-suite : Exit non-zero when no test is selected}
        {--fail-on-all-issues : Turn on every fail-on policy above}
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
        {--update-baseline : Add the run\'s deprecations to the baseline}
        {args?* : Extra engine arguments, appended verbatim after --}';

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

        // Laravel owns these; they are not engine options. `without-tty`
        // is accepted so a CI invocation copied from `artisan test`
        // does not fail on an unknown option.
        $laravelOnly = ['env', 'without-tty', 'without-databases', 'recreate-databases', 'drop-databases'];
        $symfonyOnly = ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction'];

        foreach ($this->input->getOptions() as $name => $value) {
            if ($name === 'colors' || in_array($name, $laravelOnly, true) || in_array($name, $symfonyOnly, true)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $item) {
                    if (is_string($item) && $item !== '') {
                        $command[] = '--' . $name;
                        $command[] = $item;
                    }
                }

                continue;
            }

            if ($value === true) {
                $command[] = '--' . $name;

                continue;
            }

            if (is_string($value) && $value !== '') {
                $command[] = '--' . $name;
                $command[] = $value;
            }
        }

        foreach ((array) $this->argument('args') as $argument) {
            if (is_string($argument) && $argument !== '') {
                $command[] = $argument;
            }
        }

        $command[] = '--colors=' . ($this->output->isDecorated() ? 'always' : 'never');

        $process = new Process($command, base_path(), timeout: null);

        return $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
    }
}
