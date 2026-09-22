<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs both engines over one fixture, once per format, and compares the
 * vocabularies against what probe.php records. See probe.php for why
 * shape rather than values, and for each recorded divergence's reason.
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

if (!\str_contains((string) \ini_get('xdebug.mode'), 'coverage')) {
    echo "SKIPPED   xdebug is not in coverage mode — run with -d xdebug.mode=coverage\n";

    exit(0);
}

$fixture = $root . '/conformance/fixtures/01-outcomes';
$scratch = \sys_get_temp_dir() . '/crucible-report-formats-' . \getmypid();

\mkdir($scratch, 0o777, true);

// One configuration, scoped to the fixture so the two reports describe
// the same files.
\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->source(include: ['%s'])\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
    $fixture . '/tests',
));

$status = 0;

foreach (FORMATS as $format => $spec) {
    $oracleOut = $scratch . '/oracle-' . $format . '.xml';
    $ourOut    = $scratch . '/crucible-' . $format . '.xml';

    $oracleCommand = ['php', '-d', 'xdebug.mode=coverage', $root . '/conformance/phpunit-oracle.php', $spec['option'], $oracleOut];
    $ourCommand    = ['php', '-d', 'xdebug.mode=coverage', $root . '/crucible', '--configuration', $scratch . '/crucible.php', $spec['option'], $ourOut];

    if ($spec['coverage']) {
        $oracleCommand[] = '--coverage-filter';
        $oracleCommand[] = $fixture . '/tests';
    }

    $oracleCommand[] = $fixture . '/tests';

    \capture($oracleCommand, $scratch);
    \capture($ourCommand, $scratch);

    $oracle   = \vocabulary($oracleOut);
    $crucible = \vocabulary($ourOut);

    if ($oracle === [] || $crucible === []) {
        \printf(
            "%-11s NOTHING TO COMPARE — oracle %d shapes, crucible %d shapes\n",
            $format,
            \count($oracle),
            \count($crucible),
        );

        $status = 2;

        continue;
    }

    $shared = \array_values(\array_intersect($oracle, $crucible));

    \printf("%-11s %d of the oracle's %d shapes\n", $format, \count($shared), \count($oracle));

    foreach ([
        ['the oracle emits, Crucible does not', \array_values(\array_diff($oracle, $crucible)), ORACLE_ONLY[$format]],
        ['Crucible emits, the oracle does not', \array_values(\array_diff($crucible, $oracle)), CRUCIBLE_ONLY[$format]],
    ] as [$label, $found, $recorded]) {
        $drift = \Drift::between($found, $recorded);

        if (!$drift->clean()) {
            $status = 1;
        }

        echo '  ', $label, ': ', $drift->report('shapes', 'a shape nobody explained'), "\n";
    }
}

exit($status);
