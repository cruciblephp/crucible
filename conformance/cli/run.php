<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The command-line snapshot harness: every case in cases.php run through
 * the real `crucible` binary on a fresh copy of its fixture project, its
 * stdout, stderr and exit code compared with expected/<case>.txt.
 *
 * Conformance proves Crucible's outcomes against PHPUnit; nothing held
 * Crucible's OWN command line still — its notes, its early exits, what it
 * prints and in which order. A change to how a run is put together must
 * leave every snapshot as it was, or update it on purpose.
 *
 *     php conformance/cli/run.php                 # compare
 *     php conformance/cli/run.php --update        # re-record (read the diff)
 *     php conformance/cli/run.php green red       # some cases
 *
 * Normalised before comparison, because they move without behaviour
 * moving: durations, the timing bars, and the absolute paths of the
 * project copy and of Crucible itself.
 */

$root = \dirname(__DIR__, 2);

/** @var array<non-empty-string, array{project: non-empty-string, argv: list<string>}> $cases */
$cases = require __DIR__ . '/cases.php';

require \dirname(__DIR__) . '/process.php';

$given     = $_SERVER['argv'] ?? [];
$arguments = \array_values(\array_filter(\array_slice(\is_array($given) ? $given : [], 1), \is_string(...)));

$update = \in_array('--update', $arguments, true);
$only   = \array_values(\array_filter($arguments, static fn(string $a): bool => $a !== '--update'));

/** Copy a fixture project to a fresh directory. */
function copyProject(string $from, string $to): void
{
    @\mkdir($to, 0o777, true);

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($items as $item) {
        if (!$item instanceof SplFileInfo) {
            continue;
        }

        $target = $to . \substr($item->getPathname(), \strlen($from));

        if ($item->isDir()) {
            @\mkdir($target, 0o777, true);
        } else {
            \copy($item->getPathname(), $target);
            \chmod($target, $item->getPerms() & 0o777);
        }
    }
}

/** What moves between runs without behaviour moving. */
function normalise(string $text, string $project, string $root): string
{
    $text = \str_replace([$project, $root], ['<project>', '<crucible>'], $text);
    $text = (string) \preg_replace('/\d+\.\d+ ?(m?s)\b/', '<t>$1', $text);
    $text = (string) \preg_replace('/[█▏▎▍▌▋▊▉·]+/u', '<bar>', $text);
    $text = \sortTreeRows($text);

    return \rtrim((string) \preg_replace('/[ \t]+$/m', '', $text)) . "\n";
}

/**
 * The run tree orders siblings by time, slowest first (FoldingTree), and
 * a fixture's tests all take next to nothing: which sibling is "slower"
 * is noise, and would move between machines with no behaviour moving.
 * Each node's children are compared as a set: sorted by name, each
 * subtree kept under its parent, with uniform branch glyphs.
 */
function sortTreeRows(string $text): string
{
    $out  = [];
    $rows = [];

    foreach (\explode("\n", $text) as $line) {
        if (\preg_match('/^((?:[│ ] {2})*)(?:├─|└─) (.*)$/u', $line, $match) === 1) {
            $rows[] = [\intdiv(\mb_strlen($match[1]), 3), $match[2]];

            continue;
        }

        $out   = [...$out, ...\renderTree($rows)];
        $rows  = [];
        $out[] = $line;
    }

    return \implode("\n", [...$out, ...\renderTree($rows)]);
}

/**
 * @param list<array{int, string}> $rows depth => row text, in tree order
 *
 * @return list<string>
 */
function renderTree(array $rows, int $depth = 0): array
{
    $children = [];
    $count    = \count($rows);

    for ($i = 0; $i < $count; $i++) {
        if ($rows[$i][0] !== $depth) {
            continue;
        }

        $subtree = [];

        for ($j = $i + 1; $j < $count && $rows[$j][0] > $depth; $j++) {
            $subtree[] = $rows[$j];
        }

        $children[] = [$rows[$i][1], $subtree];
    }

    \usort($children, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

    $lines = [];

    foreach ($children as [$row, $subtree]) {
        $lines[] = \str_repeat('   ', $depth) . '+- ' . $row;
        $lines   = [...$lines, ...\renderTree($subtree, $depth + 1)];
    }

    return $lines;
}

/**
 * @param list<string> $argv
 *
 * @return non-empty-string the snapshot
 */
function runCase(string $root, string $project, array $argv): string
{
    $copy = \sys_get_temp_dir() . '/crucible-cli-' . \bin2hex(\random_bytes(4));

    \copyProject(__DIR__ . '/projects/' . $project, $copy);

    [$exit, $stdout, $stderr] = \run_process(
        [PHP_BINARY, $root . '/crucible', ...$argv],
        $copy,
        ['PATH' => (string) \getenv('PATH'), 'HOME' => (string) \getenv('HOME'), 'NO_COLOR' => '1', 'TERM' => 'dumb', 'CRUCIBLE_ROOT' => $root],
    );

    \exec('rm -rf ' . \escapeshellarg($copy));

    return 'argv: ' . \implode(' ', $argv) . "\nexit: " . $exit . "\n--- stdout\n" . \normalise($stdout, $copy, $root)
        . "--- stderr\n" . \normalise($stderr, $copy, $root);
}

$failed  = 0;
$written = 0;

foreach ($cases as $name => $case) {
    if ($only !== [] && !\in_array($name, $only, true)) {
        continue;
    }

    $actual   = \runCase($root, $case['project'], $case['argv']);
    $file     = __DIR__ . '/expected/' . $name . '.txt';
    $expected = \is_file($file) ? (string) \file_get_contents($file) : null;

    if ($update) {
        if ($expected !== $actual) {
            \file_put_contents($file, $actual);
            $written++;
            \printf("WROTE     %s\n", $name);
        }

        continue;
    }

    if ($expected === $actual) {
        \printf("SAME      %s\n", $name);

        continue;
    }

    $failed++;
    \printf("CHANGED   %s\n", $name);

    $a = \tempnam(\sys_get_temp_dir(), 'exp');
    $b = \tempnam(\sys_get_temp_dir(), 'act');
    \file_put_contents((string) $a, $expected ?? "(no snapshot)\n");
    \file_put_contents((string) $b, $actual);
    \passthru('diff -u ' . \escapeshellarg((string) $a) . ' ' . \escapeshellarg((string) $b) . ' | tail -n +3 | sed "s/^/          /"');
    @\unlink((string) $a);
    @\unlink((string) $b);
}

if ($update) {
    \printf("\n%d snapshot(s) written.\n", $written);

    exit(0);
}

\printf("\n%s\n", $failed === 0 ? 'Every snapshot holds.' : $failed . ' snapshot(s) changed.');

exit($failed === 0 ? 0 : 1);
