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
 *
 * The suite carries a name, so `--testsuite` and `--exclude-testsuite`
 * select it like any PHP suite (D-125). A name filter reaches it only
 * when it is named: `--testsuite vitest --filter cart` runs
 * `vitest run -t <pattern>`, while `--filter cart` alone selects PHP
 * tests and leaves the JS suite out, with a note saying so.
 */
final readonly class VitestSuite
{
    /**
     * @param non-empty-string       $directory the JS project root (holds package.json and the vitest install)
     * @param ?non-empty-string      $binary    the vitest executable; null = `<directory>/node_modules/.bin/vitest`
     * @param list<non-empty-string> $related   absolute changed-file paths for `vitest related`; [] = the whole suite
     * @param non-empty-string       $name      what `--testsuite` and `--exclude-testsuite` call it
     * @param ?non-empty-string      $filter    the run's --filter, when this suite was named with --testsuite
     */
    public function __construct(
        public string $directory = '.',
        public ?string $binary = null,
        public array $related = [],
        public string $name = 'vitest',
        public ?string $filter = null,
    ) {}

    /**
     * @param list<non-empty-string> $related
     */
    public function relatedTo(array $related): self
    {
        return new self($this->directory, $this->binary, $related, $this->name, $this->filter);
    }

    /**
     * @param non-empty-string $filter
     */
    public function filtered(string $filter): self
    {
        return new self($this->directory, $this->binary, $this->related, $this->name, $filter);
    }
}
