<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The raw diff: how many options the oracle offers, how many Crucible offers,
 * and which of the oracle's its parser refuses — grouped by name family for a
 * first read. What each refusal *costs to cover* is ledger.php's job.
 *
 *     php conformance/probes/20-cli-surface/compare.php
 */

require __DIR__ . '/probe.php';

\require_oracle();

$oracle   = \oracle_options();
$crucible = \crucible_options();
$rejected = \rejected_options($oracle);

$families = [
    'coverage formats'      => '/^--(coverage|path-coverage|branch-coverage|warm-coverage|only-summary|show-uncovered|require-coverage|disable-coverage|exclude-source)/',
    'issue display'         => '/^--display-/',
    'fail-on / do-not-fail' => '/^--(do-not-)?fail-on/',
    'listing'               => '/^--list-/',
    'stop-on'               => '/^--stop-on-/',
    'testdox variants'      => '/^--testdox-/',
    'xml configuration'     => '/configuration|^--no-configuration/',
];

$buckets   = \array_fill_keys(\array_keys($families), []);
$remaining = [];

foreach ($rejected as $option) {
    foreach ($families as $label => $pattern) {
        if (\preg_match($pattern, $option) === 1) {
            $buckets[$label][] = $option;

            continue 2;
        }
    }

    $remaining[] = $option;
}

\printf("oracle options:   %d\n", \count($oracle));
\printf("crucible options: %d\n", \count($crucible));
\printf("rejected by crucible's parser: %d\n\n", \count($rejected));

foreach ($buckets as $label => $options) {
    if ($options !== []) {
        \printf("%-24s %2d   %s\n", $label, \count($options), \implode(' ', $options));
    }
}

\printf("\n%-24s %2d\n", 'everything else', \count($remaining));

foreach (\array_chunk($remaining, 6) as $chunk) {
    echo '    ' . \implode(' ', $chunk) . "\n";
}
