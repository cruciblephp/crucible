<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * What a tag ships, checked before it is tagged for good.
 *
 * .gitattributes has always said "verify before tagging — the archive for a
 * tag is immutable", and that was a comment someone had to remember. This is
 * the same verification as a gate: the Composer archive for a ref must carry
 * the engine a consumer runs, and must carry none of what .gitattributes
 * marks export-ignore — the design record, the fixtures, the oracles'
 * rebuild scripts, Crucible's own self-hosting config.
 *
 * Two lists of what must be absent, on purpose. The export-ignore paths are
 * read from .gitattributes, so a path added there is held here with no edit.
 * But reading ONLY that file cannot catch the failure that matters most: a
 * line deleted from .gitattributes, where the rule and the archive then agree
 * and the check passes while the tests ship. NEVER_SHIP is the floor that
 * file cannot lower.
 *
 * Run: php .github/scripts/check-archive.php [ref]   (default HEAD)
 */

/** Paths a consumer's vendor/cruciblephp/crucible cannot work without. */
const REQUIRED = [
    'composer.json',
    'crucible',
    'crucible.1',
    'LICENSE',
    'README.md',
    'MANUAL.md',
    'HELP.md',
    'phpstan/extension.neon',
    'src/Version.php',
];

/**
 * Never in the package, whatever .gitattributes says: the suite, the fixture
 * corpora, the design record, and Crucible's own self-hosting config.
 */
const NEVER_SHIP = [
    'tests',
    'conformance',
    'benchmarks',
    'spec',
    'DESIGN.md',
    'RESEARCH.md',
    'crucible.php',
];

/** Runs a command and returns its stdout, or exits naming the command that failed. */
function run(string $command): string
{
    $output = [];
    \exec($command, $output, $status);
    if ($status !== 0) {
        \fwrite(STDERR, "failed: {$command}\n");
        exit(2);
    }

    return \implode("\n", $output);
}

$ref = $argv[1] ?? 'HEAD';
if (\preg_match('/^[A-Za-z0-9._\/-]+$/', $ref) !== 1) {
    \fwrite(STDERR, "not a ref: {$ref}\n");
    exit(2);
}

$attributes = \file_get_contents('.gitattributes');
if ($attributes === false) {
    \fwrite(STDERR, "cannot read .gitattributes\n");
    exit(2);
}

/** @var list<string> $ignored */
$ignored = [];
foreach (\explode("\n", $attributes) as $line) {
    if (\preg_match('#^/(\S+)\s+export-ignore\b#', \trim($line), $match) === 1) {
        $ignored[] = $match[1];
    }
}
if ($ignored === []) {
    \fwrite(STDERR, ".gitattributes declares no export-ignore paths — nothing to hold the archive to\n");
    exit(2);
}

$entries = \array_values(\array_filter(
    \explode("\n", \run('git archive --format=tar ' . \escapeshellarg($ref) . ' | tar -t')),
    static fn(string $entry): bool => $entry !== '',
));
$shipped = [];
foreach ($entries as $entry) {
    $shipped[\rtrim($entry, '/')] = true;
}

$problems = [];
foreach (\array_unique([...NEVER_SHIP, ...$ignored]) as $path) {
    foreach (\array_keys($shipped) as $entry) {
        if ($entry === $path || \str_starts_with($entry, $path . '/')) {
            $problems[] = \in_array($path, NEVER_SHIP, true)
                ? "ships {$entry}, which must never ship"
                : "ships {$entry}, which .gitattributes marks export-ignore";

            break;
        }
    }
}
foreach (REQUIRED as $path) {
    if (!isset($shipped[$path])) {
        $problems[] = "does not ship {$path}";
    }
}

if ($problems !== []) {
    \fwrite(STDERR, "The archive for {$ref}:\n");
    foreach ($problems as $problem) {
        \fwrite(STDERR, "  {$problem}\n");
    }
    exit(1);
}

\printf(
    "The archive for %s: %d entries, the %d required paths present, none of the %d export-ignored ones.\n",
    $ref,
    \count($entries),
    \count(REQUIRED),
    \count($ignored),
);
