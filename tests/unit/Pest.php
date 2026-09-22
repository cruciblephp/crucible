<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * Suite-level pest-dialect configuration (spec §3), exercised by the
 * dialect self-tests: a bare pest() hook is global to the suite tree,
 * a scoped chain assigns class/trait/group to matched files, and
 * dataset() declares shared datasets.
 */

use LucianoPereira\Crucible\Tests\Dialect\BrewsCoffee;
use LucianoPereira\Crucible\Tests\Dialect\HigherOrderCase;

\pest()->beforeEach(function (): void {
    $this->fromSuiteConfig = 'loaded';
});

// The compact printer declaration (D-066) — Crucible's default console
// IS the compact dot printer, so this dogfoods the grammar and pins
// that a configured testdox would yield to it (CLI flags still win).
\pest()->printer()->compact();

\pest()
    ->extend(HigherOrderCase::class)
    ->use(BrewsCoffee::class)
    ->group('pest-configured')
    ->in('Dialect/PestDialectConfig*');

\dataset('beans', ['arabica', 'robusta']);

\dataset('lazy squares', static fn(): Generator => yield from [[2, 4], [3, 9]]);
