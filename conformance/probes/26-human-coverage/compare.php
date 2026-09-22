<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * One run per engine, every coverage report each can write, and the one
 * number they must all agree on. See probe.php for why layout is not
 * compared.
 */

require __DIR__ . '/probe.php';
require __DIR__ . '/../20-cli-surface/probe.php';

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
 * @return ?array{0: int, 1: int}
 */
function lines(string $file, string $pattern): ?array
{
    if (!\is_file($file)) {
        return null;
    }

    if (\preg_match($pattern, (string) \file_get_contents($file), $match) !== 1) {
        return null;
    }

    return [(int) $match[1], (int) $match[2]];
}

$fixture = $root . '/conformance/fixtures/01-outcomes';
$scratch = \sys_get_temp_dir() . '/crucible-human-coverage-' . \getmypid();

\mkdir($scratch, 0o777, true);

\file_put_contents($scratch . '/crucible.php', \sprintf(
    "<?php\ndeclare(strict_types=1);\nuse LucianoPereira\\Crucible\\Configuration\\Crucible;\nreturn Crucible::configure()\n    ->bootstrap('%s')\n    ->source(include: ['%s'])\n    ->testSuite('probe', '%s');\n",
    $root . '/src/Compat/phpunit-aliases.php',
    $fixture . '/tests',
    $fixture . '/tests',
));

// One Crucible run writing all three, because the point is that they
// cannot disagree with each other.
\capture([
    'php', '-d', 'xdebug.mode=coverage', $root . '/crucible',
    '--configuration', $scratch . '/crucible.php',
    '--coverage-text=' . $scratch . '/crucible.txt',
    '--coverage-html', $scratch . '/html',
    '--coverage-clover', $scratch . '/crucible.xml',
], $scratch);

\capture([
    'php', '-d', 'xdebug.mode=coverage', $root . '/conformance/phpunit-oracle.php',
    '--coverage-text=' . $scratch . '/oracle.txt',
    '--coverage-filter', $fixture . '/tests', $fixture . '/tests',
], $scratch);

$ours   = \lines($scratch . '/crucible.txt', READ['crucible']);
$theirs = \lines($scratch . '/oracle.txt', READ['oracle']);

if ($ours === null || $theirs === null) {
    \fwrite(STDERR, "A text report was not produced or could not be read — the probe proved nothing.\n");

    exit(2);
}

$document = new \DOMDocument();
$document->load($scratch . '/crucible.xml');
$xpath = new \DOMXPath($document);

$clover = [
    (int) $xpath->evaluate('string(/coverage/project/metrics/@coveredstatements)'),
    (int) $xpath->evaluate('string(/coverage/project/metrics/@statements)'),
];

$failures = [];

\printf("text     %d of %d\nclover   %d of %d\noracle   %d of %d\n", $ours[0], $ours[1], $clover[0], $clover[1], $theirs[0], $theirs[1]);

if ($ours !== $clover) {
    $failures[] = 'the text report and the Clover report of the same run disagree';
}

if ($ours !== $theirs) {
    $failures[] = 'the text report and the incumbent disagree about the same code';
}

// The HTML says it too, to whatever precision it rounds to.
$rate = $ours[1] === 0 ? 0.0 : 100 * $ours[0] / $ours[1];
$html = \is_file($scratch . '/html/index.html') ? (string) \file_get_contents($scratch . '/html/index.html') : '';

if ($html === '') {
    $failures[] = 'no HTML report was written';
} elseif (!\str_contains($html, \sprintf('%.2f%%', $rate))) {
    $failures[] = \sprintf('the HTML report does not state %.2f%%, which the same run measured', $rate);
} else {
    \printf("html     states %.2f%%\n", $rate);
}

echo "\n";

foreach ($failures as $failure) {
    echo '  ! ', $failure, "\n";
}

echo $failures === [] ? "every report of the run agrees, and matches the incumbent\n" : \sprintf("%d problem(s).\n", \count($failures));

exit($failures === [] ? 0 : 1);
