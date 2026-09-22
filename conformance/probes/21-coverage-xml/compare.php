<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs both writers over one fixture and compares their vocabularies
 * against what probe.php records. See probe.php for why shape rather
 * than values, and for each recorded divergence's reason.
 */

require __DIR__ . '/probe.php';
require __DIR__ . '/../../drift.php';

$root    = \dirname(__DIR__, 3);
$oracles = require $root . '/conformance/oracles-registry.php';

if (!\file_exists($root . '/' . $oracles['phpunit-main']['probe'])) {
    echo "SKIPPED   the phpunit oracle is not installed: ", $oracles['phpunit-main']['install'], "\n";

    exit(0);
}

if (\ini_get('xdebug.mode') === false || !\str_contains((string) \ini_get('xdebug.mode'), 'coverage')) {
    echo "SKIPPED   xdebug is not in coverage mode — run with -d xdebug.mode=coverage\n";

    exit(0);
}

$fixture   = $root . '/conformance/fixtures/01-outcomes';
$scratch   = \sys_get_temp_dir() . '/crucible-coverage-xml-' . \getmypid();
$oracleOut = $scratch . '/oracle';
$ourOut    = $scratch . '/crucible';

\mkdir($scratch, 0o777, true);

// One configuration, scoped to the fixture so the two reports describe
// the same files.
\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->source(include: ['%s'])\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
    $fixture . '/tests',
));

\capture([
    'php', '-d', 'xdebug.mode=coverage', $root . '/conformance/phpunit-oracle.php',
    '--coverage-xml', $oracleOut, '--coverage-filter', $fixture . '/tests', $fixture . '/tests',
], $scratch);

\capture([
    'php', '-d', 'xdebug.mode=coverage', $root . '/crucible',
    '--configuration', $scratch . '/crucible.php', '--coverage-xml', $ourOut,
], $scratch);

$oracle   = \vocabulary($oracleOut);
$crucible = \vocabulary($ourOut);

if ($oracle === [] || $crucible === []) {
    \fwrite(STDERR, "Neither report was produced — the probe proved nothing.\n");

    exit(2);
}

$shared = \array_values(\array_intersect($oracle, $crucible));

\printf("shared shapes: %d of the oracle's %d\n\n", \count($shared), \count($oracle));

$status = 0;

foreach ([
    ['the oracle emits, Crucible does not', \array_values(\array_diff($oracle, $crucible)), ORACLE_ONLY],
    ['Crucible emits, the oracle does not', \array_values(\array_diff($crucible, $oracle)), CRUCIBLE_ONLY],
] as [$label, $found, $recorded]) {
    $drift = \Drift::between($found, $recorded);

    echo $drift->report($label, 'a new divergence: record why, or close it'), "\n";

    $status = $drift->clean() ? $status : 1;
}

\capture(['rm', '-rf', $scratch], \sys_get_temp_dir());

exit($status);
