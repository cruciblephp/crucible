<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 *
 * A shared dataset file (spec §4): everything in Datasets/*.php loads
 * with the parent directory as its scope.
 */

\dataset('brews', [
    'short'  => 'espresso',
    'longer' => 'filter',
]);

/**
 * Real-world datasets commonly compose many sub-generators via
 * `yield from` (confirmed against spatie/laravel-data's own
 * tests/Datasets/RulesDataset.php — ~35 of them, ~400 total rows)
 * — each restarting its own implicit 0-based key numbering.
 * PestScopes::materialize() must not preserve those keys, or every
 * sub-generator after the first silently overwrites the previous
 * one's rows at the colliding array offsets: without the fix, this
 * dataset materializes to 3 rows (0, 1, 2 — the last one written at
 * each offset), not the correct 5.
 */
function espressoShots(): Generator
{
    yield ['espresso', 1];
    yield ['espresso', 2];
}

function filterShots(): Generator
{
    yield ['filter', 1];
    yield ['filter', 2];
    yield ['filter', 3];
}

\dataset('composed shots', function (): Generator {
    yield from \espressoShots();
    yield from \filterShots();
});
