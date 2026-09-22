<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Installs whichever oracles (ORACLES.md) are missing, from the one
 * registry every other consumer reads.
 *
 * Written for CI, which needs all of them and must not carry its own
 * copy of the commands — a workflow with its own copy is how mockery
 * ended up installed there in the form that cannot start. Usable by
 * hand too:
 *
 *     php conformance/install-oracles.php                  # all of them
 *     php conformance/install-oracles.php mockery-main     # just one
 *
 * Already-present oracles are left alone, so it is safe to re-run.
 */

$root    = \dirname(__DIR__);
$oracles = require __DIR__ . '/oracles-registry.php';

$wanted = \array_slice($argv, 1);

foreach ($wanted as $name) {
    if (!isset($oracles[$name])) {
        \fwrite(STDERR, \sprintf("Unknown oracle \"%s\". Known: %s\n", $name, \implode(', ', \array_keys($oracles))));

        exit(2);
    }
}

$status = 0;

foreach ($oracles as $name => $spec) {
    if ($wanted !== [] && !\in_array($name, $wanted, true)) {
        continue;
    }

    if (\file_exists($root . '/' . $spec['probe'])) {
        \printf("PRESENT   %s\n", $name);

        continue;
    }

    \printf("INSTALLING %s — unlocks %s\n", $name, $spec['unlocks']);

    // Run from the repository root: every command in the registry is
    // written relative to it, the way ORACLES.md documents them.
    \passthru('cd ' . \escapeshellarg($root) . ' && ' . $spec['install'], $exit);

    if ($exit !== 0 || !\file_exists($root . '/' . $spec['probe'])) {
        \fwrite(STDERR, \sprintf("FAILED    %s (probe %s still absent)\n", $name, $spec['probe']));
        $status = 1;

        continue;
    }

    \printf("INSTALLED %s\n", $name);
}

exit($status);
