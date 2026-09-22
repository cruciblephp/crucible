<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Re-runs the README's real-world parity benchmarks from benchmarks/manifest.json:
 * clones each project at its pinned ref, installs Crucible over it, runs the suite,
 * and compares the tally against the recorded figures. Development tool; needs git,
 * composer and network access.
 *
 *     php benchmarks/run.php            # every case with a pinned ref
 *     php benchmarks/run.php monolog    # one case
 *     php benchmarks/run.php --keep     # leave the checkouts in place to inspect
 */

$root = \dirname(__DIR__);

$manifest = \json_decode(
    (string) \file_get_contents($root . '/benchmarks/manifest.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$arguments = \array_slice($argv, 1);
$keep      = \in_array('--keep', $arguments, true);
$selected  = \array_values(\array_filter($arguments, static fn(string $a): bool => !\str_starts_with($a, '--')));

/**
 * @return array{int, string}
 */
function shell(string $command, string $cwd): array
{
    $process = \proc_open(
        ['bash', '-lc', $command],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
    );

    if ($process === false) {
        return [255, ''];
    }

    $output = (string) \stream_get_contents($pipes[1]) . (string) \stream_get_contents($pipes[2]);

    return [\proc_close($process), $output];
}

/**
 * The tally from Crucible's summary line.
 *
 * @return array<string, int>
 */
function tally(string $output): array
{
    $counts = [];

    if (\preg_match('/Tests: (\d+)\./', $output, $match) === 1) {
        $counts['tests'] = (int) $match[1];
    }

    foreach (['Passed' => 'passed', 'Failed' => 'failed'] as $label => $key) {
        if (\preg_match('/' . $label . ': (\d+)/', $output, $match) === 1) {
            $counts[$key] = (int) $match[1];
        }
    }

    return $counts;
}

$failures = 0;
$skipped  = 0;
$ran      = 0;

foreach ($manifest['cases'] as $name => $case) {
    if ($selected !== [] && !\in_array($name, $selected, true)) {
        continue;
    }

    if ($case['ref'] === null) {
        echo \sprintf(
            "SKIP      %s — no pinned ref. Record the exact tag or commit the figures were\n"
            . "          measured against in benchmarks/manifest.json; an unpinned benchmark\n"
            . "          proves nothing about a future run.\n",
            $name,
        );
        $skipped++;

        continue;
    }

    $missing = \array_values(\array_filter(
        $case['platform']['extensions'] ?? [],
        static fn(string $extension): bool => !\extension_loaded($extension),
    ));

    if ($missing !== []) {
        echo \sprintf(
            "SKIP      %s — needs ext-%s, which this machine does not load. The recorded\n"
            . "          figure was measured with it present; installing without it makes those\n"
            . "          tests skip and the tally stops meaning what it claims.\n",
            $name,
            \implode(', ext-', $missing),
        );
        $skipped++;

        continue;
    }

    $checkout = \sys_get_temp_dir() . '/crucible-benchmark-' . $name;
    \shell('rm -rf ' . \escapeshellarg($checkout), $root);

    $ran++;

    echo \sprintf("RUN       %s @ %s\n", $name, $case['ref']);

    // init + fetch rather than `git clone --branch`: that form takes a
    // tag or a branch and fails with "Remote branch not found" on a
    // commit, which is what every case but schema-org happens to pin.
    // The one Pest 5 benchmark was therefore unreproducible by the
    // command documented to reproduce it. This form takes all three,
    // and stays shallow.
    [$exit, $output] = \shell(\sprintf(
        'git init -q %1$s && git -C %1$s remote add origin %2$s'
            . ' && git -C %1$s fetch -q --depth 1 origin %3$s && git -C %1$s checkout -q FETCH_HEAD',
        \escapeshellarg($checkout),
        \escapeshellarg($case['repository']),
        \escapeshellarg($case['ref']),
    ), $root);

    if ($exit !== 0) {
        echo '          checkout failed: ' . \trim($output) . "\n";

        // Asked only once the fetch has actually failed, so a tag that
        // happens to look like hex is still fetched on its own terms.
        // git's own message ("couldn't find remote ref") does not point
        // at the fix, and the prefix IS valid in a local clone, which
        // makes it a confusing thing to be told is missing.
        if (\preg_match('/^[0-9a-f]{7,39}$/', $case['ref']) === 1) {
            echo \sprintf(
                "          %s may be an abbreviated commit. A remote resolves no prefixes:\n"
                . "          record the full 40-character SHA in benchmarks/manifest.json.\n",
                $case['ref'],
            );
        }

        $failures++;

        continue;
    }

    $lastOutput = '';
    $failedStep = null;

    foreach ($case['steps'] as $step) {
        $command = \str_replace('{CRUCIBLE}', $root, $step);

        [$exit, $lastOutput] = \shell($command, $checkout);

        // The final step is the suite itself: a non-zero exit there is a result, not a
        // broken run, and the tally comparison below is what judges it.
        if ($exit !== 0 && $step !== \end($case['steps'])) {
            $failedStep = $command;

            break;
        }
    }

    if ($failedStep !== null) {
        echo '          step failed: ' . $failedStep . "\n";
        echo '          ' . \trim(\substr($lastOutput, -400)) . "\n";
        $failures++;

        continue;
    }

    $actual   = \tally($lastOutput);
    $expected = $case['expected'];
    $drift    = [];

    foreach (['tests', 'passed', 'failed'] as $metric) {
        if (($actual[$metric] ?? -1) !== $expected[$metric]) {
            $drift[] = \sprintf('%s: expected %d, got %s', $metric, $expected[$metric], $actual[$metric] ?? 'none');
        }
    }

    if ($exit !== $expected['exit']) {
        $drift[] = \sprintf('exit: expected %d, got %d', $expected['exit'], $exit);
    }

    if ($drift === []) {
        echo \sprintf("MATCHES   %s — %d/%d\n", $name, $expected['passed'], $expected['tests']);
    } else {
        $failures++;
        echo \sprintf("DRIFT     %s\n", $name);

        foreach ($drift as $problem) {
            echo '          - ' . $problem . "\n";
        }
    }

    if (($case['status'] ?? '') === 'reconstructed') {
        echo "          note: steps were reconstructed from the design record. Once a run\n";
        echo "          confirms them, set \"status\": \"verified\" in the manifest.\n";
    }

    if (!$keep) {
        \shell('rm -rf ' . \escapeshellarg($checkout), $root);
    }
}

if ($skipped > 0) {
    echo \sprintf("\n%d case(s) skipped.\n", $skipped);
}

echo match (true) {
    $ran === 0      => "\nNothing ran.\n",
    $failures === 0 => "\nEvery benchmark that ran matches its recorded figures.\n",
    default         => \sprintf("\n%d benchmark(s) drift.\n", $failures),
};

exit($failures === 0 ? 0 : 1);
