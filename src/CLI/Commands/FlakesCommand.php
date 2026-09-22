<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Flakiness\OrderDependencyHunter;

use function array_slice;
use function file;
use function getmypid;
use function is_array;
use function is_file;
use function is_resource;
use function is_string;
use function json_decode;
use function max;
use function printf;
use function proc_close;
use function proc_open;
use function random_int;
use function realpath;
use function str_starts_with;
use function sys_get_temp_dir;
use function unlink;

use const PHP_BINARY;
use const PHP_EOL;

/**
 * The flakes hunt (G4, iDFlakies protocol): baseline in declared
 * order, N seeded random rounds, then every new failure is
 * replayed with its exact seed and run in isolation to be
 * classified — order-dependent (the seed reproduces it), broken
 * (fails alone too), or non-deterministic. Each run is a child
 * process, so rounds cannot pollute each other.
 */
final class FlakesCommand
{
    /**
     * @param list<string>     $argv
     */
    public function execute(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        $binary = realpath($argv[0] ?? '');

        if ($binary === false) {
            print 'Cannot resolve the crucible binary path for child runs.' . PHP_EOL;

            return 1;
        }

        /** @var list<non-empty-string> $childArgv */
        $childArgv = [];
        $arguments = array_slice($argv, 1);

        for ($i = 0; isset($arguments[$i]); $i++) {
            $argument = $arguments[$i];

            if ($argument === 'flakes' || $argument === '' || str_starts_with($argument, '--rounds=')) {
                continue;
            }

            if ($argument === '--rounds') {
                $i++;

                continue;
            }

            $childArgv[] = $argument;
        }

        $eventFile = sys_get_temp_dir() . '/crucible-flakes-' . getmypid() . '.ndjson';

        $hunter = new OrderDependencyHunter(
            fn(array $extra): array => $this->childOutcomes($binary, [...$childArgv, ...$extra], $eventFile, $workingDirectory),
            static function (string $note): void {
                print $note . PHP_EOL;
            },
        );

        $rounds = max(1, $options->rounds ?? 5);
        $seed   = random_int(0, 1_000_000_000);

        printf('Hunting order-dependent tests: %d random-order round(s), base seed %d.' . PHP_EOL, $rounds, $seed);

        try {
            $report = $hunter->hunt($rounds, $seed);
        } finally {
            if (is_file($eventFile)) {
                unlink($eventFile);
            }
        }

        print PHP_EOL;

        if ($report->baselineFailures !== []) {
            printf('Already failing in declaration order — fix these first:' . PHP_EOL);

            foreach ($report->baselineFailures as $id) {
                print '  - ' . $id . PHP_EOL;
            }
        }

        foreach ($report->orderDependent as $id => $exposingSeed) {
            printf('ORDER-DEPENDENT: %s' . PHP_EOL, $id);
            printf('  reproduce with: crucible --order-by random --random-order-seed %d' . PHP_EOL, $exposingSeed);
        }

        foreach ($report->nonDeterministic as $id) {
            printf('NON-DETERMINISTIC: %s (fails intermittently; no order to blame)' . PHP_EOL, $id);
        }

        foreach ($report->brokenAlone as $id) {
            printf('BROKEN: %s (fails even in isolation)' . PHP_EOL, $id);
        }

        if ($report->clean()) {
            printf('No flaky tests found in %d round(s).' . PHP_EOL, $rounds);

            return 0;
        }

        return 1;
    }

    /**
     * One child run for the flakes hunt: output discarded, the NDJSON
     * event stream parsed into id → outcome.
     *
     * @param non-empty-string       $binary
     * @param list<non-empty-string> $arguments
     * @param non-empty-string       $eventFile
     *
     * @return array<string, string>
     */
    private function childOutcomes(string $binary, array $arguments, string $eventFile, WorkingDirectory $workingDirectory): array
    {
        $process = proc_open(
            [PHP_BINARY, $binary, ...$arguments, '--log-events-json', $eventFile],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $workingDirectory->path,
        );

        if (is_resource($process)) {
            proc_close($process);
        }

        $outcomes = [];
        $lines    = is_file($eventFile) ? file($eventFile) : false;

        foreach ($lines === false ? [] : $lines as $line) {
            $event = json_decode($line, true);

            if (is_array($event)
                && ($event['event'] ?? null) === 'test:finish'
                && is_string($event['id'] ?? null)
                && is_string($event['outcome'] ?? null)
            ) {
                $outcomes[$event['id']] = $event['outcome'];
            }
        }

        return $outcomes;
    }
}
