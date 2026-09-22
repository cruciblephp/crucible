<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Static analysis of the surfaces that compile against something
 * Crucible deliberately does not depend on.
 *
 * The default `composer analyse` excludes them, because requiring
 * PHPUnit, Mockery or Laravel to analyse Crucible would contradict the
 * thing Crucible is. Each tier here is analysed only when its oracle
 * (ORACLES.md) is installed, and skipped by name otherwise — the same
 * rule the conformance lanes follow, for the same reason: an oracle
 * that is not installed cannot disagree with anything.
 *
 *     php phpstan/oracles.php
 */

$root = \dirname(__DIR__);

/** Probe paths and install commands come from the one registry (ORACLES.md). */
$oracles = require $root . '/conformance/oracles-registry.php';

$tiers = [
    'compat' => [
        'label'  => 'phpunit/mockery compatibility',
        'config' => __DIR__ . '/oracles.neon',
        'needs'  => ['phpunit-main', 'mockery-main'],
    ],
    'bridges' => [
        'label'  => 'laravel/livewire/pest bridges',
        'config' => __DIR__ . '/oracles-laravel.neon',
        'needs'  => ['livewire-oracle'],
    ],
    'duplication' => [
        'label'  => 'the duplication check',
        'config' => __DIR__ . '/oracles-duplication.neon',
        'needs'  => ['phpcpd-main'],
    ],
    'drift' => [
        'label'  => "the probe's incumbent-side verdict classification",
        'config' => __DIR__ . '/oracles-pest.neon',
        'needs'  => ['pest-oracle'],
    ],
];

// Named tiers are *required*: a missing oracle fails instead of
// skipping. CI passes the tiers whose oracles that job builds, so a
// job cannot go green having quietly analysed nothing — the same rule
// the browser job applies to its own skips.
$required = \array_slice($argv, 1);

foreach ($required as $key) {
    if (!isset($tiers[$key])) {
        \fwrite(STDERR, \sprintf("Unknown tier \"%s\". Known: %s\n", $key, \implode(', ', \array_keys($tiers))));

        exit(2);
    }
}

$status  = 0;
$skipped = 0;


foreach ($tiers as $key => $tier) {
    if ($required !== [] && !\in_array($key, $required, true)) {
        continue;
    }

    $name    = $tier['label'];
    $missing = [];

    foreach ($tier['needs'] as $oracle) {
        if (!\file_exists($root . '/' . $oracles[$oracle]['probe'])) {
            $missing[$oracle] = $oracles[$oracle]['install'];
        }
    }

    if ($missing !== []) {
        $wasRequired = \in_array($key, $required, true);

        if ($wasRequired) {
            $status = 1;
        } else {
            $skipped++;
        }

        \printf(
            "%s %s (needs %s)\n",
            $wasRequired ? 'MISSING  ' : 'SKIPPED  ',
            $name,
            \implode(' and ', \array_keys($missing)),
        );

        foreach ($missing as $oracle => $install) {
            \printf("            %s: %s\n", $oracle, $install);
        }

        continue;
    }

    \printf("ANALYSING %s\n", $name);

    \passthru(\sprintf(
        '%s analyse --configuration=%s --no-progress --memory-limit=1G',
        \escapeshellarg($root . '/vendor/bin/phpstan'),
        \escapeshellarg($tier['config']),
    ), $exit);

    if ($exit !== 0) {
        $status = 1;
    }
}

if ($skipped > 0) {
    \printf("\n%d tier(s) skipped for a missing oracle — not a verdict either way.\n", $skipped);
}

exit($status);
