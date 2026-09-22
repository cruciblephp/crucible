<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Bridge\Laravel;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

use function base_path;
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

        foreach (['filter', 'testsuite', 'group', 'exclude-group', 'parallel', 'shard', 'order-by', 'random-order-seed', 'coverage-clover'] as $option) {
            $value = $this->option($option);

            if (is_string($value) && $value !== '') {
                $command[] = '--' . $option;
                $command[] = $value;
            }
        }

        foreach (['stop-on-failure', 'stop-on-defect', 'testdox', 'coverage', 'update-baseline'] as $flag) {
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
