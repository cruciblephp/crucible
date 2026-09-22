<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Vitest;

/**
 * A JavaScript test suite Crucible orchestrates through Vitest (D-079).
 * Registered with `->vitest(...)` in `crucible.php`; a normal `crucible` run
 * spawns `vitest run --reporter=json` in this directory after the PHP
 * suite and folds its results into the same run — one command, one
 * report, one exit code.
 *
 * Under impact selection (D-080), the suite is narrowed to the change
 * set: {@see \LucianoPereira\Crucible\Impact\VitestImpact} rewrites it with
 * the changed files that fall under its directory, and the run turns
 * from `vitest run` into `vitest related <files> --run` — Vitest's own
 * module graph then decides which JS tests re-run.
 */
final readonly class VitestSuite
{
    /**
     * @param non-empty-string       $directory the JS project root (holds package.json and the vitest install)
     * @param ?non-empty-string      $binary    the vitest executable; null = `<directory>/node_modules/.bin/vitest`
     * @param list<non-empty-string> $related   absolute changed-file paths for `vitest related`; [] = the whole suite
     */
    public function __construct(
        public string $directory = '.',
        public ?string $binary = null,
        public array $related = [],
    ) {}
}
