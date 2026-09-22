<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/* The namespace axis, over oracle.php's transport. */

/**
 * Derived from excluded.php: reason 'arch' enrols, it cannot excuse.
 *
 * @return list<non-empty-string>
 */
function archMatchers(): array
{
    /** @var array<non-empty-string, non-empty-string> $excluded */
    $excluded = require __DIR__ . '/excluded.php';

    $matchers = [];

    foreach ($excluded as $matcher => $reason) {
        if ($reason === 'arch') {
            $matchers[] = $matcher;
        }
    }

    \sort($matchers);

    return $matchers;
}

/**
 * The target corpus, checked rather than cast.
 *
 * @return list<non-empty-string>
 */
function archTargets(): array
{
    /** @var mixed $targets */
    $targets = require __DIR__ . '/arch-targets.php';

    if (!\is_array($targets) || $targets === []) {
        throw new RuntimeException('arch-targets.php must return a non-empty list of namespaces');
    }

    /** @var list<non-empty-string> $targets */
    return $targets;
}

/**
 * Both forms recorded: an empty target passes a matcher and its negation alike.
 *
 * @return array<non-empty-string, array{p: non-empty-string, n: non-empty-string}>
 */
function archGrid(): array
{
    /** @var mixed $grid */
    $grid = require __DIR__ . '/arch-grid.php';

    if (!\is_array($grid) || $grid === []) {
        throw new RuntimeException('arch-grid.php must return a non-empty matcher => row map');
    }

    /** @var array<non-empty-string, array{p: non-empty-string, n: non-empty-string}> $grid */
    return $grid;
}

/**
 * Absolute path to the fixture package's PSR-4 root.
 *
 * @return non-empty-string
 */
function archFixtureRoot(): string
{
    $root = \realpath(__DIR__ . '/arch-fixture/src');

    if ($root === false) {
        throw new RuntimeException('arch-fixture/src is missing');
    }

    return $root;
}

/**
 * Registers the fixture on the loader Pest's arch engine reads, at runtime.
 *
 * The oracle checkout is read-only, so composer.json cannot carry it.
 * ✓ Measured 2026-09-06 against pest 5.1.1.
 */
function archPrelude(): string
{
    // __DIR__ is the oracle's tests/, where the generated suite is written.
    return '$loader = require __DIR__ . ' . \var_export('/../vendor/autoload.php', true) . ";\n"
        . '$loader->addPsr4(' . \var_export('ArchFixture\\', true) . ', ' . \var_export(\archFixtureRoot(), true) . ");\n\n";
}

/**
 * arch-grid.php's source, written by regenerate-arch.php and never by hand.
 *
 * @param array<non-empty-string, array{p: non-empty-string, n: non-empty-string}> $grid
 */
function archGridFile(array $grid, int $targets): string
{
    $width = 0;

    // +2 for the key's quotes: pint aligns `=>` to the quoted key.
    foreach (\array_keys($grid) as $matcher) {
        $width = \max($width, \strlen($matcher) + 2);
    }

    $rows = '';

    foreach ($grid as $matcher => $row) {
        $rows .= \sprintf(
            "    %-{$width}s => ['p' => '%s', 'n' => '%s'],\n",
            "'" . $matcher . "'",
            $row['p'],
            $row['n'],
        );
    }

    return <<<PHP_FILE
        <?php

        declare(strict_types=1);
        /*
         * This file is part of Crucible.
         *
         * Copyright (c) 2026 Luciano Federico Pereira
         * All rights reserved.
         */

        /*
         * What pest 5.1.1 answers, obtained by EXECUTING it against
         * arch-fixture/. One character per target in arch-targets.php's
         * order ({$targets} of them): `p` pass, `f` fail, `x` REFUSED.
         * `'p'` is the positive form, `'n'` the same call through `->not`.
         *
         * A matcher aimed at an unresolvable namespace RAISES; folding that
         * into `f` would record a verdict about a universe never built.
         *
         * Written by regenerate-arch.php, never by hand.
         *
         * @return array<non-empty-string, non-empty-string>
         */

        return [
        {$rows}];

        PHP_FILE;
}
