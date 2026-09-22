<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Runs the same tests twice and compares the reports byte for byte.
 *
 * The claim `--reproducible` makes is only worth something end to end:
 * a unit test can prove one writer takes an instant, but not that every
 * emitter in a real run agrees. So this drives the actual CLI.
 *
 * It also runs the WITHOUT-the-flag control, and fails if that comes out
 * identical too. A probe where both arms agree proves the flag works
 * exactly as well as a probe where the flag does nothing, and cannot
 * tell those apart — the control is what separates them.
 */

$root   = \dirname(__DIR__, 3);
$binary = $root . '/crucible';
$temp   = \sys_get_temp_dir() . '/crucible-reproducible-' . \getmypid();

@\mkdir($temp, 0o777, true);

/**
 * @param list<string> $extra
 *
 * @return array<string, string> emitted file => contents
 */
function emit(string $binary, string $temp, string $tag, array $extra): array
{
    $targets = [
        'json'   => $temp . '/' . $tag . '.json',
        'sarif'  => $temp . '/' . $tag . '.sarif',
        'clover' => $temp . '/' . $tag . '.clover',
    ];

    $command = \escapeshellarg(\PHP_BINARY) . ' -d xdebug.mode=coverage ' . \escapeshellarg($binary)
        . ' --filter ' . \escapeshellarg('testTheFlagIsOffUntilAskedFor')
        . ' --report ' . \escapeshellarg('json:' . $targets['json'] . ',sarif:' . $targets['sarif'])
        . ' --coverage-clover ' . \escapeshellarg($targets['clover'])
        . ' ' . \implode(' ', \array_map('escapeshellarg', $extra))
        . ' > /dev/null 2>&1';

    \exec($command);

    $out = [];

    foreach ($targets as $format => $path) {
        $out[$format] = \is_file($path) ? (string) \file_get_contents($path) : '';
    }

    return $out;
}

$withA = \emit($binary, $temp, 'with-a', ['--reproducible']);
$withB = \emit($binary, $temp, 'with-b', ['--reproducible']);
$noneA = \emit($binary, $temp, 'none-a', []);
$noneB = \emit($binary, $temp, 'none-b', []);

$findings = 0;

foreach ($withA as $format => $contents) {
    if ($contents === '') {
        echo 'BROKEN    ', $format, ' was never written — the probe compared nothing', "\n";
        $findings++;

        continue;
    }

    if ($contents !== $withB[$format]) {
        echo 'DRIFT     ', $format, " differs between two --reproducible runs of the same tests\n";
        $findings++;

        continue;
    }

    // The control. Without it, a flag that silently stopped working
    // would still show two identical arms and report OK.
    if ($noneA[$format] === $noneB[$format]) {
        echo 'INERT     ', $format, " is identical WITHOUT --reproducible too, so this proves nothing about the flag\n";
        $findings++;

        continue;
    }

    echo 'OK        ', $format, " is byte-identical with the flag and varies without it\n";
}

\array_map('unlink', \glob($temp . '/*') ?: []);
@\rmdir($temp);

exit($findings === 0 ? 0 : 1);
