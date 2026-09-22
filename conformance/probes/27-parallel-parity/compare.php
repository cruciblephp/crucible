<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs the fixture both ways and diffs the streams. See probe.php for
 * what is deliberately not compared.
 */

require __DIR__ . '/probe.php';
require __DIR__ . '/../20-cli-surface/probe.php';

$root = \dirname(__DIR__, 3);

/**
 * The per-test facts a run states, keyed by test id, with the per-run
 * ones stripped.
 *
 * @return array<string, string>
 */
function verdicts(string $file): array
{
    $verdicts = [];

    foreach (\explode("\n", (string) \file_get_contents($file)) as $line) {
        if ($line === '') {
            continue;
        }

        $event = \json_decode($line, true);

        if (!\is_array($event) || ($event['event'] ?? '') !== 'test:finish') {
            continue;
        }

        foreach (VOLATILE as $key) {
            unset($event[$key]);
        }

        unset($event['event']);

        // The throw site survives the process boundary; the frames
        // beneath it are the run's own call stack and do not.
        if (isset($event['error']['trace']) && \is_array($event['error']['trace'])) {
            $event['error']['trace'] = \array_slice($event['error']['trace'], 0, 1);
        }

        $id = (string) $event['id'];
        unset($event['id']);

        \ksort($event);

        $verdicts[$id] = (string) \json_encode($event);
    }

    return $verdicts;
}

/**
 * @return array<string, mixed>
 */
function runEvent(string $file, string $name): array
{
    foreach (\explode("\n", (string) \file_get_contents($file)) as $line) {
        if ($line === '') {
            continue;
        }

        $event = \json_decode($line, true);

        if (\is_array($event) && ($event['event'] ?? '') === $name) {
            foreach (VOLATILE as $key) {
                unset($event[$key]);
            }

            return $event;
        }
    }

    return [];
}

$fixture = $root . '/conformance/fixtures/01-outcomes';
$scratch = \sys_get_temp_dir() . '/crucible-parallel-parity-' . \getmypid();

\mkdir($scratch, 0o777, true);

\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
));

foreach (['sequential' => [], 'parallel' => ['--parallel', '2']] as $mode => $extra) {
    \capture([
        'php', $root . '/crucible', '--configuration', $scratch . '/crucible.php',
        ...$extra, '--log-events-json', $scratch . '/' . $mode . '.ndjson',
    ], $scratch);
}

$sequential = \verdicts($scratch . '/sequential.ndjson');
$parallel   = \verdicts($scratch . '/parallel.ndjson');

if ($sequential === [] || $parallel === []) {
    \fwrite(STDERR, "One of the runs produced no test events — the probe proved nothing.\n");

    exit(2);
}

$failures = [];

\printf("sequential %d tests, parallel %d tests\n\n", \count($sequential), \count($parallel));

foreach ($sequential as $id => $verdict) {
    if (!isset($parallel[$id])) {
        $failures[] = $id . ': the parallel run never reported it';

        continue;
    }

    if ($parallel[$id] !== $verdict) {
        $failures[] = \sprintf("%s:\n      sequential %s\n      parallel   %s", $id, $verdict, $parallel[$id]);
    }
}

foreach (\array_keys($parallel) as $id) {
    if (!isset($sequential[$id])) {
        $failures[] = $id . ': only the parallel run reported it';
    }
}

// The run-scoped events too: the plan size a reader shows progress
// against, and the tally it prints at the end.
foreach (['run:start', 'run:finish'] as $name) {
    $one = \runEvent($scratch . '/sequential.ndjson', $name);
    $two = \runEvent($scratch . '/parallel.ndjson', $name);

    unset($one['duration'], $two['duration']);

    if ($one !== $two) {
        $failures[] = \sprintf("%s differs:\n      sequential %s\n      parallel   %s", $name, \json_encode($one), \json_encode($two));
    }
}

foreach ($failures as $failure) {
    echo '  ! ', $failure, "\n";
}

echo $failures === []
    ? "every test reports the same verdict either way, and both runs describe themselves identically\n"
    : \sprintf("\n%d difference(s).\n", \count($failures));

exit($failures === [] ? 0 : 1);
