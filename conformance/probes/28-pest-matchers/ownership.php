<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Every matcher on the public surface is exercised by SOMETHING that
 * would fail if it were wrong — asserted, not assumed.
 *
 * The sweep states its own size (44 of 116) and that is a true number
 * answering a question about the GRID. The question a reader asks is
 * about the SURFACE, and until this gate nothing answered it: 21 of
 * Expectation's own methods turned out to be executed by no test, no
 * probe, no example and no fixture at all. Two of them were wrong.
 *
 * Ownership is DERIVED, never declared. An earlier design had
 * excluded.php name a covering test per exclusion; that registers a
 * string pointing at a name, and the named test can be renamed, gutted
 * or stop calling the matcher with the gate still green. SkipReasonsTest
 * works because the registered thing IS the skip string it scans for,
 * and ApprovedGeneratorRule works because it counts real call sites.
 * Neither has that indirection, so neither does this: a matcher is
 * covered when a line inside it was OBSERVED to execute.
 *
 * The coverage suite is run HERE rather than read from wherever it was
 * last left, so the artifact this gate judges is the one this run
 * produced — the same reason analyse:generated regenerates its sources
 * every time. A stale or missing artifact is an error, never a skip:
 * a skip is indistinguishable from a pass in a summary line, which is
 * this project's worst output.
 *
 * KNOWN LIMIT, stated rather than buried: this proves EXECUTED, not
 * ASSERTED. A matcher run incidentally by an unrelated test satisfies
 * it. The window is per-test and observed (D-047), so discovery-time
 * execution does not count — but incidental execution does. Closing
 * that means breaking each matcher and requiring a red, which is a
 * mutation run, not a per-run gate.
 *
 *     php conformance/probes/28-pest-matchers/ownership.php
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/oracle.php';

use LucianoPereira\Crucible\Dialect\Pest\Expectation;

$root     = \dirname(__DIR__, 3);
$artifact = $root . '/.crucible.cache/coverage-lines.json';
$relative = 'src/Dialect/Pest/Expectation.php';

// 1. Produce the evidence. Exit code first: ownership judged from a run
//    that failed would describe a tree nobody accepted.
\printf("RUNNING   the coverage suite, so the artifact judged below is this run's\n");

$suite = 0;
\passthru(\sprintf('php -d xdebug.mode=coverage %s --coverage', \escapeshellarg($root . '/crucible')), $suite);

if ($suite !== 0) {
    \printf("BROKEN    the coverage suite exited %d; ownership cannot be judged\n", $suite);

    exit($suite);
}

// 2. Read what it wrote.
$observed = \observedLines($artifact, $relative);

if ($observed === []) {
    \printf("BROKEN    %s recorded no executed line of %s\n", $artifact, $relative);

    exit(1);
}

// 3. Classify the whole surface.
$ranges  = \matcherRanges();
$grid    = \probeGrid();
$covered = [];
$swept   = [];
$unowned = [];

foreach (\probeSurface() as $matcher => $required) {
    if (!isset($ranges[$matcher])) {
        \printf("BROKEN    %s is on the surface but has no source range\n", $matcher);

        exit(1);
    }

    [$from, $to] = $ranges[$matcher];
    $ran         = false;

    for ($line = $from; $line <= $to; $line++) {
        if (isset($observed[$line])) {
            $ran = true;

            break;
        }
    }

    if ($ran) {
        $covered[] = $matcher;

        continue;
    }

    // Swept by the grid is real coverage, in the incumbent's own
    // process — which is why it does not appear in this suite's window.
    if (isset($grid[$matcher])) {
        $swept[] = $matcher;

        continue;
    }

    $unowned[] = $matcher;
}

\sort($unowned);

foreach ($unowned as $matcher) {
    \printf("UNOWNED   %s is executed by no test and swept by no grid row\n", $matcher);
}

\printf(
    "%-9s %d of %d declared matchers owned: %d executed by the suite, %d swept by the grid, %d unowned\n",
    $unowned === [] ? 'OK' : 'CHECKED',
    \count($covered) + \count($swept),
    \count($covered) + \count($swept) + \count($unowned),
    \count($covered),
    \count($swept),
    \count($unowned),
);

exit($unowned === [] ? 0 : 1);

/**
 * Where each matcher's body lives, so an executed line can be
 * attributed to it.
 *
 * @return array<non-empty-string, array{0: int, 1: int}>
 */
function matcherRanges(): array
{
    $ranges = [];

    foreach ((new \ReflectionClass(Expectation::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $name  = $method->getName();
        $start = $method->getStartLine();
        $end   = $method->getEndLine();

        if ($start !== false && $end !== false) {
            $ranges[$name] = [$start, $end];
        }
    }

    return $ranges;
}

/**
 * The lines of one file that some test was OBSERVED to execute (D-047),
 * pooled over every test in the run.
 *
 * @return array<int, true>
 */
function observedLines(string $artifact, string $file): array
{
    $raw = \is_file($artifact) ? \file_get_contents($artifact) : false;

    if ($raw === false) {
        \printf("BROKEN    %s is missing; the coverage suite wrote no per-test map\n", $artifact);

        exit(1);
    }

    $decoded = \json_decode($raw, true);

    if (!\is_array($decoded) || !isset($decoded['tests']) || !\is_array($decoded['tests'])) {
        \printf("BROKEN    %s carries no tests map\n", $artifact);

        exit(1);
    }

    $lines = [];

    foreach ($decoded['tests'] as $files) {
        if (!\is_array($files) || !isset($files[$file]) || !\is_array($files[$file])) {
            continue;
        }

        foreach ($files[$file] as $line) {
            if (\is_int($line)) {
                $lines[$line] = true;
            }
        }
    }

    return $lines;
}
