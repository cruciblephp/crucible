<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Probe 30: what the analyser knows after each assertion, on Crucible's
 * PHPStan extension against the incumbents' (phpstan-phpunit and
 * pest-plugin-phpstan). Probe 28's method, applied to types:
 *
 *   - one fixture, analysed by both stacks unchanged: it is written in
 *     PHPUnit's namespace and Pest's global functions, which is exactly
 *     the suite a migration brings;
 *   - a recorded table of the incumbent's answers (record.php, written
 *     by regenerate.php from the live incumbent), so check 1 runs where
 *     no incumbent is installed;
 *   - every disagreement absent or named (divergences.php), with its
 *     cause;
 *   - both forms swept: every assertion has its negation beside it.
 *
 * A fixture line is `dumpType(...); // label`. The label, not the line
 * number, is the key, so editing a fixture never reshuffles the record.
 */

use LucianoPereira\Crucible\CLI\Phpstan;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

require_once \dirname(__DIR__, 3) . '/vendor/autoload.php';

/** @return non-empty-string */
function probeRoot(): string
{
    return \dirname(__DIR__, 3);
}

/**
 * The incumbent stack's install, or null when it is absent.
 *
 * @return array{directory: ?non-empty-string, install: non-empty-string}
 */
function incumbentStack(): array
{
    $root = \probeRoot();

    /** @var array<string, array{probe: string, install: non-empty-string}> $oracles */
    $oracles = require $root . '/conformance/oracles-registry.php';

    $directory = $root . '/pest-oracle';
    $complete  = \is_file($directory . '/vendor/bin/phpstan')
        && \is_file($directory . '/vendor/phpstan/phpstan-phpunit/extension.neon')
        && \is_file($directory . '/vendor/pestphp/pest-plugin-phpstan/extension.neon');

    return [
        'directory' => $complete ? $directory : null,
        'install'   => $oracles['pest-oracle']['install'],
    ];
}

/**
 * file:line => label, from the fixtures' trailing comments.
 *
 * @return array<string, non-empty-string>
 */
function fixtureLabels(): array
{
    $labels = [];

    $files = \glob(__DIR__ . '/fixture/*.php');

    foreach ($files === false ? [] : $files as $file) {
        $lines = \file($file);

        foreach ($lines === false ? [] : $lines as $index => $line) {
            if (\preg_match('#dumpType\(.*\);\s*//\s*(\S+)\s*$#', $line, $match) === 1) {
                $labels[\basename($file) . ':' . ($index + 1)] = $match[1];
            }
        }
    }

    return $labels;
}

/**
 * Analyse the fixtures with one stack.
 *
 * @param 'crucible'|'incumbent' $stack
 *
 * @return array{types: array<string, string>, other: list<string>}
 */
function analyseFixtures(string $stack): array
{
    $root = \probeRoot();
    $temp = \sys_get_temp_dir() . '/crucible-probe30-' . $stack . '-' . \getmypid();

    @\mkdir($temp, 0o777, true);

    if ($stack === 'crucible') {
        $binary   = $root . '/vendor/bin/phpstan';
        $autoload = $root . '/vendor/autoload.php';
        $includes = [$root . '/phpstan/extension.neon'];
    } else {
        $directory = \incumbentStack()['directory'];

        if ($directory === null) {
            throw new RuntimeException('The incumbent stack is not installed.');
        }

        $binary   = $directory . '/vendor/bin/phpstan';
        $autoload = $directory . '/vendor/autoload.php';
        $includes = [
            $directory . '/vendor/phpstan/phpstan-phpunit/extension.neon',
            $directory . '/vendor/pestphp/pest-plugin-phpstan/extension.neon',
        ];
    }

    \file_put_contents($temp . '/phpstan.neon', PhpstanNeon::analysis($includes, [
        'level'  => 'max',
        'paths'  => [__DIR__ . '/fixture'],
        'tmpDir' => $temp . '/cache',
    ]));

    $report = Phpstan::analyse(
        $binary,
        ['--configuration=' . $temp . '/phpstan.neon', '--autoload-file=' . $autoload, '--memory-limit=1G'],
        new WorkingDirectory($root),
    );

    if (\is_string($report)) {
        throw new RuntimeException(\sprintf('The %s stack produced no report: %s', $stack, $report));
    }

    $labels = \fixtureLabels();
    $types  = [];
    $other  = [];

    foreach ($report->messages as $message) {
        $key = \basename($message->file) . ':' . $message->line;

        if (\str_starts_with($message->message, 'Dumped type: ') && isset($labels[$key])) {
            $types[$labels[$key]] = \substr($message->message, \strlen('Dumped type: '));

            continue;
        }

        $other[] = $key . ': ' . $message->message;
    }

    \ksort($types);

    return ['types' => $types, 'other' => $other];
}
