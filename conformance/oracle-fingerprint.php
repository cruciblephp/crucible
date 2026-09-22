<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * What the oracles currently installed here actually are.
 *
 * The oracles are large and optional (ORACLES.md), so the results proved
 * against them are recorded rather than re-proved on every machine. A
 * record is only worth something if it names *which* code was proved
 * against, so this prints an identity that can be compared:
 *
 *     php conformance/oracle-fingerprint.php
 *
 * A git checkout reports its commit; a composer install reports the
 * locked reference of each package that matters. Neither is a hash of
 * the directory — those drift with timestamps, caches and vendor
 * layout, and would compare unequal for two identical installs.
 */

$root = \dirname(__DIR__);

/**
 * @return ?non-empty-string
 */
function commit_of(string $directory): ?string
{
    // Only when the directory is its own repository: `git rev-parse`
    // inside a non-repo walks up and answers for the parent, which
    // would record Crucible's own HEAD as the oracle's identity.
    if (!\is_dir($directory . '/.git')) {
        return null;
    }

    $process = \proc_open(
        ['git', 'rev-parse', 'HEAD'],
        [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
        $pipes,
        $directory,
    );

    if ($process === false) {
        return null;
    }

    $output = \trim((string) \stream_get_contents($pipes[1]));

    return \proc_close($process) === 0 && $output !== '' ? $output : null;
}

/**
 * @param list<string> $names
 *
 * @return list<string>
 */
function locked(string $lockFile, array $names): array
{
    if (!\is_file($lockFile)) {
        return [];
    }

    $decoded = \json_decode((string) \file_get_contents($lockFile), true);
    $lines   = [];

    foreach (\array_merge($decoded['packages'] ?? [], $decoded['packages-dev'] ?? []) as $package) {
        if (!\in_array($package['name'] ?? '', $names, true)) {
            continue;
        }

        $lines[] = \sprintf(
            '%s %s %s',
            $package['name'],
            $package['version'],
            \substr((string) ($package['dist']['reference'] ?? $package['source']['reference'] ?? '?'), 0, 12),
        );
    }

    \sort($lines);

    return $lines;
}

/**
 * @return non-empty-string
 */
function version_of(string $directory): string
{
    // Through the same wrapper the conformance lane runs, because that
    // is the thing being identified — the bare binary cannot even find
    // its autoloader without it.
    foreach ([__DIR__ . '/phpunit-oracle.php', $directory . '/phpunit'] as $candidate) {
        if (!\is_file($candidate)) {
            continue;
        }

        $process = \proc_open(
            ['php', $candidate, '--version'],
            [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $directory,
        );

        if ($process === false) {
            continue;
        }

        $output = (string) \stream_get_contents($pipes[1]);
        \proc_close($process);

        if (\preg_match('/([0-9]+\.[0-9]+\.[0-9]+)/', $output, $match) === 1) {
            return $match[1];
        }
    }

    return '(unknown version)';
}

/**
 * The npm oracles identify themselves by installed package version —
 * the same idea as a composer.lock reference.
 *
 * @return non-empty-string
 */
function npmVersion(string $directory, string $package): string
{
    $manifest = $directory . '/node_modules/' . $package . '/package.json';

    if (!\is_file($manifest)) {
        return '(absent)';
    }

    $decoded = \json_decode((string) \file_get_contents($manifest), true);

    return \is_array($decoded) && \is_string($decoded['version'] ?? null) ? $decoded['version'] : '(unknown)';
}

function content_hash(string $lockFile): string
{
    if (!\is_file($lockFile)) {
        return '(no lock)';
    }

    $decoded = \json_decode((string) \file_get_contents($lockFile), true);

    return \is_array($decoded) && \is_string($decoded['content-hash'] ?? null) ? $decoded['content-hash'] : '(no hash)';
}

$registry = require __DIR__ . '/oracles-registry.php';
$lines    = [];

foreach (['phpunit-main', 'mockery-main'] as $oracle) {
    $directory = $root . '/' . $oracle;

    if (!\is_dir($directory)) {
        $lines[] = $oracle . ' (absent)';

        continue;
    }

    $commit = \commit_of($directory);

    if ($commit !== null) {
        $lines[] = $oracle . ' ' . $commit;

        continue;
    }

    // A dist install has no commit to name, so it identifies itself:
    // the version its own binary reports, plus the lock's content hash,
    // which together pin the tool and everything under it.
    $lines[] = \sprintf(
        '%s %s lock:%s',
        $oracle,
        \version_of($directory),
        \substr(\content_hash($directory . '/composer.lock'), 0, 12),
    );
}

$laravel = \locked($root . '/livewire-oracle/composer.lock', [
    'laravel/framework',
    'livewire/livewire',
    'spatie/phpunit-snapshot-assertions',
]);

foreach ($laravel === [] ? ['(absent)'] : $laravel as $line) {
    $lines[] = 'livewire-oracle ' . $line;
}

foreach (['browser-oracle' => ['playwright', 'axe-core'], 'inertia-oracle' => ['@inertiajs/core']] as $oracle => $packages) {
    if (!\file_exists($root . '/' . $registry[$oracle]['probe'])) {
        $lines[] = $oracle . ' (absent)';

        continue;
    }

    foreach ($packages as $package) {
        $lines[] = $oracle . ' ' . $package . ' ' . \npmVersion($root . '/' . $oracle, $package);
    }
}

$lines[] = 'php ' . PHP_VERSION;

foreach ($lines as $line) {
    echo $line, "\n";
}

\printf("\nfingerprint sha256:%s\n", \substr(\hash('sha256', \implode("\n", $lines)), 0, 16));
