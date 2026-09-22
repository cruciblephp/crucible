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
 * numbers each reports against what probe.php records.
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

/**
 * @return array<string, string> the facts a document reports, by label
 */
function facts(string $file, array $read): array
{
    if (!\is_file($file)) {
        return [];
    }

    $document = new \DOMDocument();
    \libxml_use_internal_errors(true);

    if (!$document->load($file)) {
        \libxml_clear_errors();

        return [];
    }

    \libxml_clear_errors();

    $xpath = new \DOMXPath($document);
    $facts = [];

    foreach ($read as $label => $expression) {
        $facts[$label] = \trim((string) $xpath->evaluate($expression));
    }

    return $facts;
}

/** Numerically, so 1 and 1.00 agree — formatting is probe 22's question. */
function same(string $left, string $right): bool
{
    if ($left === $right) {
        return true;
    }

    if (!\is_numeric($left) || !\is_numeric($right)) {
        return false;
    }

    return \abs((float) $left - (float) $right) < 0.00005;
}

$fixture = $root . '/conformance/fixtures/01-outcomes';
$scratch = \sys_get_temp_dir() . '/crucible-report-values-' . \getmypid();

\mkdir($scratch, 0o777, true);

\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->source(include: ['%s'])\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
    $fixture . '/tests',
));

$disagreements = [];
$detail        = [];

foreach (FACTS as $format => $spec) {
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

    $oracle   = \facts($oracleOut, $spec['read']);
    $crucible = \facts($ourOut, $spec['read']);

    if ($oracle === [] || $crucible === []) {
        \fwrite(STDERR, \sprintf("%s: one of the documents was not produced — the probe proved nothing.\n", $format));

        exit(2);
    }

    $agreed = 0;

    foreach ($oracle as $label => $value) {
        if (\same($value, $crucible[$label] ?? '')) {
            $agreed++;

            continue;
        }

        $key             = $format . '.' . $label;
        $disagreements[] = $key;
        $detail[$key]    = \sprintf('oracle %s, crucible %s', $value === '' ? '(absent)' : $value, ($crucible[$label] ?? '') === '' ? '(absent)' : $crucible[$label]);
    }

    \printf("%-10s %d of %d numbers agree\n", $format, $agreed, \count($oracle));
}

echo "\n";

$drift = \Drift::between($disagreements, RECORDED);

foreach ($disagreements as $key) {
    if (!isset(RECORDED[$key])) {
        continue;
    }

    \printf("    %-28s %s\n", $key, $detail[$key]);
}

echo $drift->report('numbers', 'a number nobody explained'), "\n";

exit($drift->clean() ? 0 : 1);
