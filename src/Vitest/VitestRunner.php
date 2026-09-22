<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Vitest;

use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Test\TestId;

use function file_get_contents;
use function is_array;
use function is_file;
use function is_resource;
use function json_decode;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function str_starts_with;
use function sys_get_temp_dir;
use function tempnam;
use function trim;
use function unlink;
use function usleep;

/**
 * Orchestrates a JavaScript suite through Vitest (D-079) and folds its
 * results into the run. It spawns `vitest run --reporter=json` in the
 * suite directory, translates the JSON with {@see VitestReport}, and
 * emits a `test:start`/`test:finish` pair per JS test onto the same
 * NDJSON stream as the PHP suite — so they share the tree, the report,
 * and the exit code. It returns the count delta the runner merges into
 * the {@see RunSummary}; the JS tests are never Crucible-scheduled, so they
 * stay out of the D-071 complete gate and the shard hash by construction.
 *
 * Never a hang and never a silent pass: a suite whose Vitest cannot
 * start, or produces no JSON, reports one errored test with the reason,
 * so the run reflects it and fails.
 */
final readonly class VitestRunner
{
    private const int POLL_MICROSECONDS = 10_000;

    public function __construct(private Emitter $emitter) {}

    /**
     * @param list<VitestSuite> $suites
     */
    public function run(array $suites, WorkingDirectory $workingDirectory): RunSummary
    {
        $summary = new RunSummary();

        foreach ($suites as $suite) {
            $summary = $summary->plus($this->runSuite($suite, $workingDirectory));
        }

        return $summary;
    }

    private function runSuite(VitestSuite $suite, WorkingDirectory $workingDirectory): RunSummary
    {
        $directory = str_starts_with($suite->directory, '/')
            ? $suite->directory
            : $workingDirectory->path . '/' . $suite->directory;
        $binary = $suite->binary ?? $directory . '/node_modules/.bin/vitest';

        $report = tempnam(sys_get_temp_dir(), 'crucible-vitest-');
        $stderr = tempnam(sys_get_temp_dir(), 'crucible-vitest-err-');

        if ($report === false || $stderr === false) {
            return $this->couldNotRun($suite, 'Vitest report file could not be created.');
        }

        // Under impact selection (D-080) the suite carries the changed
        // files that fall under it: `vitest related <files> --run` lets
        // Vitest's own module graph pick the JS tests to re-run. Without
        // them, the whole suite runs.
        $command = $suite->related === []
            ? [$binary, 'run', '--reporter=json', '--outputFile=' . $report]
            : [$binary, 'related', ...$suite->related, '--run', '--reporter=json', '--outputFile=' . $report];

        // argv form — no shell. stdout is discarded (the JSON rides the
        // outputFile); stderr is captured for the diagnostic. A missing
        // binary is handled by the is_resource check below, so its
        // spawn warning is suppressed rather than leaked to the console.
        $process = @proc_open(
            $command,
            [STDIN, ['file', '/dev/null', 'w'], ['file', $stderr, 'w']],
            $pipes,
            $directory,
        );

        if (!is_resource($process)) {
            @unlink($report);
            @unlink($stderr);

            return $this->couldNotRun($suite, 'Vitest could not be started — is it installed in ' . $directory . '?');
        }

        while (proc_get_status($process)['running']) {
            usleep(self::POLL_MICROSECONDS);
        }

        proc_close($process);

        $json    = is_file($report) ? file_get_contents($report) : false;
        $decoded = $json === false ? null : json_decode($json, true);
        $tail    = is_file($stderr) ? trim((string) file_get_contents($stderr)) : '';

        @unlink($report);
        @unlink($stderr);

        if (!is_array($decoded)) {
            return $this->couldNotRun($suite, 'Vitest produced no JSON report.' . ($tail !== '' ? "\n" . $tail : ''));
        }

        // Paths are made relative to the working directory, not the JS
        // project root, so a JS test file reads the same way a PHP one
        // does and round-trips through `--related` — the watch loop
        // (D-081) re-feeds a failed test's file, and it must resolve. A
        // suite outside the working directory keeps absolute paths,
        // which `--related` resolves just as well.
        return $this->emit(VitestReport::translate($decoded, $workingDirectory->path));
    }

    /**
     * @param list<TestFinished> $events
     */
    private function emit(array $events): RunSummary
    {
        $counts = ['pass' => 0, 'fail' => 0, 'error' => 0, 'skip' => 0, 'incomplete' => 0, 'risky' => 0];

        foreach ($events as $event) {
            $this->emitter->emit(new TestStarted($event->test));
            $this->emitter->emit($event);
            $counts[$event->outcome->value]++;
        }

        return $this->summary($counts);
    }

    /**
     * A suite that could not run at all is one errored test, visible on
     * the stream and failing the run.
     *
     * @param non-empty-string $reason
     */
    private function couldNotRun(VitestSuite $suite, string $reason): RunSummary
    {
        $id = new TestId($suite->directory, 'vitest');

        $this->emitter->emit(new TestStarted($id));
        $this->emitter->emit(new TestFinished($id, Outcome::Errored, 0.0, new Failure($reason)));

        return new RunSummary(errored: 1);
    }

    /**
     * @param array{pass: int, fail: int, error: int, skip: int, incomplete: int, risky: int} $counts
     */
    private function summary(array $counts): RunSummary
    {
        return new RunSummary(
            passed: $counts['pass'],
            failed: $counts['fail'],
            errored: $counts['error'],
            skipped: $counts['skip'],
            incomplete: $counts['incomplete'],
            risky: $counts['risky'],
        );
    }
}
