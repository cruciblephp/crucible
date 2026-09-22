<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\Process\WorkerManifest;
use LucianoPereira\Crucible\Runner\Process\WorkerProcess;
use LucianoPereira\Crucible\Runner\Process\WorkUnit;
use LucianoPereira\Crucible\Test\TestId;

use function array_values;
use function file_put_contents;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function microtime;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use function usleep;

/**
 * Runs a mutant *cold* — the portable path that needs no pcntl. It spawns
 * an ordinary `crucible --worker` (the same machinery parallel runs use),
 * injects the mutant through the environment so the worker's autoloader
 * ({@see MutationAutoloader}) serves it, and hands the worker only the
 * covering tests. The verdict is read off the worker's NDJSON stream: a
 * covering test that fails or errors killed the mutant; a clean pass of
 * them all is an escape; a worker that never completes is an error — a
 * crashed mutant is never a silent escape.
 *
 * Slower than the warm fork (a full boot per mutant), but it runs
 * everywhere. A runner picks warm when {@see MutantApplier::isSupported}
 * and cold otherwise, so mutation testing degrades in speed, never in
 * correctness, and never crashes for want of an extension.
 */
final readonly class ColdMutantExecutor implements MutantExecutor
{
    private const int POLL_MICROSECONDS = 2_000;

    /**
     * @param non-empty-string $crucibleBinary      the crucible entry script
     * @param non-empty-string $configuration    absolute path of the configuration file
     */
    public function __construct(
        private string $crucibleBinary,
        private string $configuration,
        private WorkingDirectory $workingDirectory,
        private float $timeoutSeconds = 30.0,
    ) {}

    /**
     * Cold execution is portable — it can always run a mutant.
     */
    public function canRun(Mutant $mutant): bool
    {
        return true;
    }

    /**
     * @param list<non-empty-string> $coveringTestIds fastest-first ids (D-077); the worker runs exactly these
     */
    public function execute(Mutant $mutant, array $coveringTestIds): MutationVerdict
    {
        if ($coveringTestIds === []) {
            return MutationVerdict::notCovered($mutant);
        }

        $started    = microtime(true);
        $mutatedTmp = tempnam(sys_get_temp_dir(), 'crucible-mutant-');

        if ($mutatedTmp === false) {
            return MutationVerdict::errored($mutant, 'Could not allocate a temp file for the mutant.', 0.0);
        }

        file_put_contents($mutatedTmp, $mutant->mutatedSource);

        [$classKey, $fileKey] = MutationAutoloader::environmentKeys();

        $worker = WorkerProcess::spawn(
            $this->crucibleBinary,
            $this->workingDirectory,
            new WorkerManifest($this->configuration, $this->units($coveringTestIds)),
            [],
            // Same budget as the warm path: a mutant that loops while
            // allocating must die as a verdict, not take the machine.
            ['-d', 'memory_limit=' . MutantApplier::MEMORY_LIMIT],
            [$classKey => $mutant->class, $fileKey => $mutatedTmp],
        );

        if (!$worker instanceof \LucianoPereira\Crucible\Runner\Process\WorkerProcess) {
            @unlink($mutatedTmp);

            return MutationVerdict::errored($mutant, 'Could not spawn the cold mutation worker.', microtime(true) - $started);
        }

        $deadline = $started + $this->timeoutSeconds;
        $lines    = [];
        $timedOut = false;

        while (!$worker->atEof()) {
            foreach ($worker->readLines() as $line) {
                $lines[] = $line;
            }

            $worker->drainStderr();

            if ($worker->atEof()) {
                break;
            }

            if (microtime(true) >= $deadline) {
                $worker->terminate();
                $timedOut = true;

                break;
            }

            usleep(self::POLL_MICROSECONDS);
        }

        foreach ($worker->readLines() as $line) {
            $lines[] = $line;
        }

        $stderr = $worker->stderrTail();
        $exit   = $worker->close();

        @unlink($mutatedTmp);

        return $this->verdict($mutant, $lines, $exit, $stderr, microtime(true) - $started, $timedOut);
    }

    /**
     * Group the covering ids by file into the worker's work units — a
     * unit's test list carries the full id strings, which the manifest
     * matches exactly, so only the covering tests run.
     *
     * @param list<non-empty-string> $coveringTestIds
     *
     * @return list<WorkUnit>
     */
    private function units(array $coveringTestIds): array
    {
        /** @var array<non-empty-string, WorkUnit> $byFile */
        $byFile = [];

        foreach ($coveringTestIds as $id) {
            $testId = TestId::fromString($id);

            if (!$testId instanceof TestId) {
                continue;
            }

            $existing = $byFile[$testId->file] ?? null;
            $tests    = $existing instanceof WorkUnit ? [...($existing->tests ?? []), $id] : [$id];

            $byFile[$testId->file] = new WorkUnit($testId->file, $tests);
        }

        return array_values($byFile);
    }

    /**
     * @param list<string> $lines the worker's NDJSON output
     */
    private function verdict(Mutant $mutant, array $lines, int $exitCode, string $stderr, float $duration, bool $timedOut): MutationVerdict
    {
        if ($timedOut) {
            return MutationVerdict::timedOut($mutant, $duration);
        }

        $killer    = null;
        $finishes  = 0;
        $sawFinish = false;

        foreach ($lines as $line) {
            $event = json_decode($line, true);

            if (!is_array($event)) {
                continue;
            }

            $name = $event['event'] ?? null;

            if ($name === 'run:finish') {
                $sawFinish = true;

                continue;
            }

            if ($name !== 'test:finish' || !is_string($event['id'] ?? null) || $event['id'] === '') {
                continue;
            }

            ++$finishes;

            if ($killer === null && in_array($event['outcome'] ?? null, ['fail', 'error'], true)) {
                $killer = $event['id'];
            }
        }

        if ($killer !== null) {
            return MutationVerdict::killed($mutant, $killer, $duration);
        }

        if ($exitCode === 0 && $sawFinish && $finishes > 0) {
            return MutationVerdict::escaped($mutant, $duration);
        }

        return MutationVerdict::errored(
            $mutant,
            $stderr !== '' ? $stderr : 'The cold worker did not complete the covering tests.',
            $duration,
        );
    }
}
