<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Rewrites arch-grid.php from a live Pest, by executing it.
 *
 * BOTH forms are recorded rather than one plus inverted(). ✓ Measured
 * 2026-09-06: a target matching no symbol passes a matcher AND its
 * negation in both engines, so deriving the negated cell manufactures
 * divergences that are not there.
 *
 * Transport is regenerate.php's, plus archPrelude(): the fixture reaches
 * the incumbent by runtime PSR-4 registration, since the oracle checkout
 * is read-only. Matchers come from excluded.php, never from here.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/oracle.php';
require __DIR__ . '/arch.php';

$root   = \dirname(__DIR__, 3);
$oracle = \oracleBinary($root);

if ($oracle['binary'] === null) {
    echo "SKIP      no pest-oracle install — see ORACLES.md:\n          ", $oracle['install'], "\n";

    exit(0);
}

$matchers = \archMatchers();
$targets  = \archTargets();

$body = <<<'PHP_BODY'
    test('arch grid', function () use ($matchers, $targets): void {
        $rows = 0;

        foreach ($matchers as $matcher) {
            $positive = '';
            $negated  = '';

            foreach ($targets as $target) {
                $positive .= cell($target, $matcher);
                $negated  .= cell($target, $matcher, true);
                $rows     += 2;
            }

            echo 'ROW ', $matcher, ' ', $positive, ' ', $negated, "\n";
        }

        echo 'COUNTED ', $rows, "\n";
        expect(true)->toBeTrue();
    });

    PHP_BODY;

$output = \oracleRun(
    $oracle['binary'],
    'CrucibleArchGridTest.php',
    \archPrelude()
    . '$matchers = ' . \var_export($matchers, true) . ";\n"
    . '$targets  = ' . \var_export($targets, true) . ";\n\n"
    . \oraclePrelude()
    . "\n" . $body,
);

$grid = [];

foreach ($output as $line) {
    if (!\str_starts_with($line, 'ROW ')) {
        continue;
    }

    [, $matcher, $positive, $negated] = \explode(' ', \trim($line));

    // The count check below still passes if the missing cells are a whole row.
    if ($matcher === '' || $positive === '' || $negated === '') {
        echo "BROKEN    the incumbent returned an empty row: ", $line, "\n";

        exit(1);
    }

    $grid[$matcher] = ['p' => $positive, 'n' => $negated];
}

$counted  = \oracleCounted($output);
$expected = \count($matchers) * \count($targets) * 2;

if ($counted !== $expected || \count($grid) !== \count($matchers)) {
    echo "BROKEN    the incumbent checked ", $counted, " of ", $expected, " cells over ", \count($grid), " of ", \count($matchers), " matchers\n";

    foreach ($output as $line) {
        echo '          ', $line, "\n";
    }

    exit(1);
}

\file_put_contents(__DIR__ . '/arch-grid.php', \archGridFile($grid, \count($targets)));

\printf("WROTE     arch-grid.php — %d cells over %d matchers and %d targets\n", $counted, \count($matchers), \count($targets));

exit(0);
