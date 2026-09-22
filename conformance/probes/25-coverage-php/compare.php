<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Writes both the PHP dump and the Clover report from one run, then
 * checks the dump is plain data, announces its format, and reports the
 * same numbers the XML did. See probe.php for why the incumbent is not
 * the reference here.
 */

require __DIR__ . '/probe.php';
require __DIR__ . '/../20-cli-surface/probe.php';

$root = \dirname(__DIR__, 3);

if (!\str_contains((string) \ini_get('xdebug.mode'), 'coverage')) {
    echo "SKIPPED   xdebug is not in coverage mode — run with -d xdebug.mode=coverage\n";

    exit(0);
}

$fixture = $root . '/conformance/fixtures/01-outcomes';
$scratch = \sys_get_temp_dir() . '/crucible-coverage-php-' . \getmypid();

\mkdir($scratch, 0o777, true);

\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->source(include: ['%s'])\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
    $fixture . '/tests',
));

$dump   = $scratch . '/coverage.php';
$clover = $scratch . '/clover.xml';

// One run, two writers: the point is that they cannot disagree.
\capture([
    'php', '-d', 'xdebug.mode=coverage', $root . '/crucible',
    '--configuration', $scratch . '/crucible.php',
    '--coverage-php', $dump,
    '--coverage-clover', $clover,
], $scratch);

if (!\is_file($dump) || !\is_file($clover)) {
    \fwrite(STDERR, "The run produced no reports — the probe proved nothing.\n");

    exit(2);
}

$failures = [];

// Plain data: reading it must not need a single class of Crucible's.
if (\preg_match('/O:\d+:"/', (string) \file_get_contents($dump)) === 1) {
    $failures[] = 'the dump serializes an object, so reading it needs the class that wrote it';
}

/** @var array<string, mixed> $data */
$data = require $dump;

foreach (REQUIRED as $path) {
    $cursor = $data;

    foreach (\explode('.', $path) as $key) {
        if (!\is_array($cursor) || !\array_key_exists($key, $cursor)) {
            $failures[] = 'the dump is missing ' . $path;

            continue 2;
        }

        $cursor = $cursor[$key];
    }
}

$announced = $data['buildInformation']['crucible']['format'] ?? null;

if ($announced !== FORMAT) {
    $failures[] = \sprintf('the dump announces format %s, expected %s', \var_export($announced, true), FORMAT);
}

// The same run's two reports, counted the same way.
$executable = 0;
$covered    = 0;

/** @var array<string, array<int, int>> $lines */
$lines = $data['coverage']['lines'] ?? [];

foreach ($lines as $values) {
    foreach ($values as $value) {
        if ($value === -2) {
            continue;
        }

        $executable++;

        if ($value > 0) {
            $covered++;
        }
    }
}

$document = new \DOMDocument();
$document->load($clover);
$xpath = new \DOMXPath($document);

$statements = (int) $xpath->evaluate('string(/coverage/project/metrics/@statements)');
$hit        = (int) $xpath->evaluate('string(/coverage/project/metrics/@coveredstatements)');

\printf("dump   executable %d, covered %d\nclover statements %d, covered %d\n\n", $executable, $covered, $statements, $hit);

if ($executable !== $statements || $covered !== $hit) {
    $failures[] = 'the dump and the Clover report of the same run disagree';
}

foreach ($failures as $failure) {
    echo '  ! ', $failure, "\n";
}

echo $failures === []
    ? "the dump is plain data, announces its format, carries every promised key, and agrees with the XML\n"
    : \sprintf("\n%d problem(s).\n", \count($failures));

exit($failures === [] ? 0 : 1);
