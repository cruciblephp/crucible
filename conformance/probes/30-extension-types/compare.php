<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Two checks over one recorded table, as in probe 28.
 *
 *   1. Crucible's extension against the record. Runs everywhere.
 *   2. The record against the live incumbent, when it is installed:
 *      catches the incumbent moving under a record that still claims to
 *      describe it.
 *
 *     php conformance/probes/30-extension-types/compare.php
 */

require __DIR__ . '/probe.php';

/** @var array<string, string> $record */
$record = require __DIR__ . '/record.php';

/** @var array<string, array{crucible: string, cause: string}> $divergences */
$divergences = require __DIR__ . '/divergences.php';

$status = 0;

$crucible = \analyseFixtures('crucible');
$findings = [];

foreach ($record as $label => $incumbent) {
    $ours = $crucible['types'][$label] ?? '(no type)';

    if ($ours === $incumbent) {
        if (isset($divergences[$label])) {
            $findings[] = \sprintf("  STALE  %s: agrees now; remove its divergences row", $label);
        }

        continue;
    }

    $named = $divergences[$label] ?? null;

    if ($named !== null && $named['crucible'] === $ours) {
        continue;
    }

    $findings[] = \sprintf("  DRIFT  %s\n         incumbent: %s\n         crucible:  %s", $label, $incumbent, $ours);
}

\printf("1. Crucible against the record: %d lines, %d unexplained\n", \count($record), \count($findings));

foreach ($findings as $finding) {
    echo $finding, "\n";
}

$status = $findings === [] ? 0 : 1;

$stack = \incumbentStack();

if ($stack['directory'] === null) {
    echo "2. The record against the incumbent: SKIPPED (not installed: {$stack['install']})\n";
} else {
    $live  = \analyseFixtures('incumbent')['types'];
    $moved = [];

    foreach ($record as $label => $type) {
        if (($live[$label] ?? '(no type)') !== $type) {
            $moved[] = \sprintf('  MOVED  %s: recorded %s, live %s', $label, $type, $live[$label] ?? '(no type)');
        }
    }

    \printf("2. The record against the incumbent: %d moved\n", \count($moved));

    foreach ($moved as $line) {
        echo $line, "\n";
    }

    $status = $moved === [] ? $status : 1;
}

exit($status);
