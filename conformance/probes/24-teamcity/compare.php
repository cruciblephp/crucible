<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs both engines over one fixture and compares the service messages
 * each writes. See probe.php for why shape rather than values.
 */

require __DIR__ . '/probe.php';
require __DIR__ . '/../20-cli-surface/probe.php';
require __DIR__ . '/../../drift.php';

$root    = \dirname(__DIR__, 3);
$oracles = require $root . '/conformance/oracles-registry.php';

if (!\file_exists($root . '/' . $oracles['phpunit-main']['probe'])) {
    echo "SKIPPED   the phpunit oracle is not installed: ", $oracles['phpunit-main']['install'], "\n";

    exit(0);
}

$fixture   = $root . '/conformance/fixtures/01-outcomes';
$scratch   = \sys_get_temp_dir() . '/crucible-teamcity-' . \getmypid();
$oracleOut = $scratch . '/oracle.txt';
$ourOut    = $scratch . '/crucible.txt';

\mkdir($scratch, 0o777, true);

\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
));

\capture(['php', $root . '/conformance/phpunit-oracle.php', '--log-teamcity', $oracleOut, $fixture . '/tests'], $scratch);
\capture(['php', $root . '/crucible', '--configuration', $scratch . '/crucible.php', '--log-teamcity', $ourOut], $scratch);

$oracle   = \messages($oracleOut);
$crucible = \messages($ourOut);

if ($oracle === [] || $crucible === []) {
    \fwrite(STDERR, "One of the logs was not produced — the probe proved nothing.\n");

    exit(2);
}

$shared = \array_values(\array_intersect($oracle, $crucible));

\printf("shared messages: %d of the oracle's %d\n\n", \count($shared), \count($oracle));

$status = 0;

foreach ([
    ['the oracle emits, Crucible does not', \array_values(\array_diff($oracle, $crucible)), ORACLE_ONLY],
    ['Crucible emits, the oracle does not', \array_values(\array_diff($crucible, $oracle)), CRUCIBLE_ONLY],
] as [$label, $found, $recorded]) {
    $drift = \Drift::between($found, $recorded);

    if (!$drift->clean()) {
        $status = 1;
    }

    echo $label, ': ', $drift->report('messages', 'a message nobody explained'), "\n";
}

exit($status);
