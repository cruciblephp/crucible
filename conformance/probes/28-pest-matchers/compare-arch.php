<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Crucible's arch matchers against the recorded incumbent, cell by cell.
 *
 * Two checks over one recorded grid, as compare.php does: Crucible
 * against the record, then the record against a live Pest when the
 * oracle is installed — an incumbent that MOVED and a Crucible that
 * BROKE want opposite fixes.
 *
 * Plus one the value axis does not need: an all-`p` row means the
 * fixture holds no target the matcher says no to, so its column proves
 * nothing. See UNCOMPARED / UNPROVABLE below.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/oracle.php';
require __DIR__ . '/arch.php';

use LucianoPereira\Crucible\Architecture\Architecture;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

$root     = \dirname(__DIR__, 3);
$grid     = \archGrid();
$targets  = \archTargets();
$matchers = \archMatchers();
$fixture  = \archFixtureRoot();

// Two routes to one fixture: Crucible reads a configured Source, Pest
// reads composer's PSR-4 map. The loader registration is what lets the
// reflection-backed matchers see the fixture classes at all.
/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';
$loader->addPsr4('ArchFixture\\', $fixture);

Architecture::configure(new Source(includeDirectories: [$fixture]), new WorkingDirectory($fixture));

// `ArchFixture\Nothing` makes Crucible warn that the expectation asserts
// nothing, which is the behaviour D1 settled and a target this fixture
// carries on purpose. Left to PHP's handler it prints a stack trace per
// cell and buries the findings; swallowed silently it would hide a
// warning that appeared where none belongs. So they are counted, and the
// count is stated with the verdict.
$warnings = 0;

\set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
    $warnings++;

    return true;
}, \E_USER_WARNING);

/**
 * One cell on Crucible's own engine.
 *
 * Catches the BASE failure class, as cell.php does: naming one subclass
 * recorded the incumbent's "no" as `x`.
 *
 * @return 'p'|'f'|'x'
 */
function crucibleArchCell(string $matcher, string $target, bool $negated = false): string
{
    $expectation = new Expectation($target);

    try {
        $negated ? $expectation->not->{$matcher}() : $expectation->{$matcher}();

        return 'p';
    } catch (AssertionFailedError) {
        return 'f';
    } catch (\Throwable) {
        return 'x';
    }
}

/*
 * Matchers this FIXTURE cannot make fail, reason measured. Every cell is
 * still compared and a divergence is still a finding; only the "proves
 * nothing" flag is suppressed. Anything not listed stays red.
 */
const UNPROVABLE = [
    'toBeCasedCorrectly' => 'a class whose declared name disagrees with its path cannot be autoloaded under PSR-4 on a '
        . 'case-sensitive filesystem, and Crucible\'s universe holds what it can load — so no fixture can put a '
        . 'violating class in front of it. ✓ Measured 2026-09-07: pest\'s own layer for ArchFixture\Casing\Wrong is '
        . 'EMPTY for that same file, so the 50 cells still agree; they agree by neither engine seeing it.',
];

$findings   = 0;
$cells      = 0;
$uncompared = [];

// --- 1. Crucible against the record ---------------------------------
foreach ($matchers as $matcher) {
    $row = $grid[$matcher] ?? null;

    if ($row === null) {
        \printf("BROKEN    %s is an arch matcher with no recorded row — regenerate-arch.php\n", $matcher);

        $findings++;

        continue;
    }

    if (\strlen($row['p']) !== \count($targets) || \strlen($row['n']) !== \count($targets)) {
        \printf("BROKEN    %s has %d/%d recorded cells over %d targets\n", $matcher, \strlen($row['p']), \strlen($row['n']), \count($targets));

        $findings++;

        continue;
    }

    if (!\str_contains($row['p'], 'f')) {
        $uncompared[] = $matcher;
    }

    foreach ($targets as $index => $target) {
        foreach ([false, true] as $negated) {
            $cells++;
            $want = $negated ? $row['n'][$index] : $row['p'][$index];
            $got  = \crucibleArchCell($matcher, $target, $negated);

            if ($got === $want) {
                continue;
            }

            $findings++;

            \printf(
                "DIVERGED  %s%s over %s — pest %s, crucible %s\n",
                $negated ? 'not->' : '',
                $matcher,
                $target,
                $want,
                $got,
            );
        }
    }
}

$unprovable = \array_values(\array_filter($uncompared, static fn(string $matcher): bool => isset(UNPROVABLE[$matcher])));
$uncompared = \array_values(\array_filter($uncompared, static fn(string $matcher): bool => !isset(UNPROVABLE[$matcher])));

foreach ($unprovable as $matcher) {
    \printf("UNPROVABLE %s — %s\n", $matcher, UNPROVABLE[$matcher]);
}

foreach ($uncompared as $matcher) {
    \printf("UNCOMPARED %s — no target in the fixture makes it fail, so its column proves nothing\n", $matcher);
}

\printf(
    "          %d cells over %d matchers and %d targets, both forms; %d empty-target warnings; %d matcher(s) unprovable by this fixture\n",
    $cells,
    \count($matchers),
    \count($targets),
    $warnings,
    \count($unprovable),
);

if ($findings === 0 && $uncompared === []) {
    echo "OK        crucible answers the arch grid exactly as the record says pest does\n";
}

exit($findings === 0 && $uncompared === [] ? 0 : 1);
