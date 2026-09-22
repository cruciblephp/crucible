<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Rewrites sweep.php from the installed incumbent.
 *
 * sweep.php says of itself that it is regenerated only by re-running the
 * oracle and never by hand — which was an instruction with no tool
 * behind it. A grid that can only be produced by editing characters in
 * a string is a grid somebody will eventually edit to agree with
 * whatever Crucible does, and that is the single failure this whole
 * probe exists to prevent.
 *
 * The corpus is REQUIRED from the one canonical values.php by absolute
 * path, for the same reason compare.php does it: nothing is copied next
 * to the oracle, so there is no second tree to keep in sync. It is also
 * what lets the corpus hold a value var_export() cannot carry -- an
 * SplFileInfo, an ArrayObject or a Closure all export as a
 * __set_state() call the far side cannot resolve.
 *
 * Run it after changing values.php or matchers.php, never to make a
 * disagreement go away.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/oracle.php';

$root     = \dirname(__DIR__, 3);
$corpus   = \probeCorpus();
$matchers = \probeMatchers();
$oracle   = \oracleBinary($root);

if ($oracle['binary'] === null) {
    echo 'SKIPPED   nothing to regenerate from: ', $oracle['install'], "\n";

    exit(1);
}

$body = <<<'PHP'
    test('sweep', function () use ($corpus, $matchers): void {
        crucibleSweep($corpus, $matchers);
        expect(true)->toBeTrue();
    });

    PHP;

$output = \oracleRun(
    $oracle['binary'],
    'CrucibleSweepTest.php',
    '$corpus   = require ' . \var_export(__DIR__ . '/values.php', true) . ";\n"
    . '$matchers = ' . \var_export($matchers, true) . ";\n\n"
    . \oraclePrelude()
    . "\n" . $body,
);

$grid = [];

foreach ($output as $line) {
    if (\str_starts_with($line, 'ROW ')) {
        [, $matcher, $row] = \explode(' ', $line, 3);
        $grid[$matcher]    = \trim($row);
    }
}

$counted = \oracleCounted($output);

// The same reconciliation compare.php makes: a generator that ran
// nothing must not write an empty grid over a good one.
$want = \count($matchers) * \count($corpus);

if ($counted !== $want || \count($grid) !== \count($matchers)) {
    \printf("BROKEN    the incumbent answered %d of %d cells over %d of %d matchers — sweep.php left alone\n", $counted, $want, \count($grid), \count($matchers));

    exit(1);
}

foreach ($grid as $matcher => $row) {
    if (\strlen($row) !== \count($corpus)) {
        \printf("BROKEN    row %s came back %d long for %d values — sweep.php left alone\n", $matcher, \strlen($row), \count($corpus));

        exit(1);
    }
}

$body = '';

foreach ($matchers as $matcher) {
    $body .= \sprintf("    %-22s => '%s',\n", "'" . $matcher . "'", $grid[$matcher]);
}

$header = <<<'PHP'
    <?php

    declare(strict_types=1);
    /*
     * This file is part of Crucible.
     *
     * Copyright (c) 2026 Luciano Federico Pereira
     * All rights reserved.
     */

    /*
     * What Pest 5.1.1 answers for every zero-argument matcher over every
     * value in the shared corpus, obtained by EXECUTING it. One character
     * per value, in corpus order: `p` pass, `f` fail, `x` REFUSED — the
     * incumbent declined to answer at all, raising InvalidExpectationValue
     * because the subject is the wrong type for that matcher.
     *
     * The third state is not decoration. Catching Throwable and calling it
     * `f` records "the assertion failed" for a case where nothing was
     * asserted, and a matcher that does not exist would read the same way.
     * 373 of these cells were recorded as failures before the distinction
     * existed.
     *
     * A grid rather than a row-per-case list so a change shows up as a
     * single flipped character on one line, which is what makes a diff of
     * this file readable when the incumbent moves.
     *
     * Written by regenerate.php, never by hand: editing a row here would
     * make the probe agree with whatever Crucible does, which is the one
     * thing it exists to prevent.
     *
     * @return array<non-empty-string, non-empty-string>
     */

    return [

    PHP;

\file_put_contents(__DIR__ . '/sweep.php', $header . $body . "];\n");

\printf("OK        sweep.php rewritten: %d matchers over %d values, %d cells\n", \count($matchers), \count($corpus), $counted);
