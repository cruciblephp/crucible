<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Vitest\VitestSuite;

use function count;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * The JavaScript tier of impact selection (D-080), symmetric to
 * {@see ImpactSelection} for PHP. A configured Vitest suite is narrowed
 * to the change set: a changed file under the suite's directory becomes
 * a `vitest related <file>` argument, and Vitest's own module graph then
 * decides which JS tests re-run — Crucible orchestrates rather than
 * reimplements the JS dependency walk (the D-079 principle).
 *
 * The safety direction matches the PHP tier: any deletion widens every
 * suite to a full run (a deleted file's dependents are invisible to the
 * graph), and a suite with no JS change is dropped from the run. A
 * dropped suite is named, never silently skipped.
 */
final readonly class VitestImpact
{
    /**
     * @param list<VitestSuite> $suites
     *
     * @return array{list<VitestSuite>, list<string>} the suites to run (unaffected ones dropped, each
     *                                                narrowed to its related files) and the human notes
     */
    public static function select(array $suites, ChangedFiles $changed, WorkingDirectory $workingDirectory): array
    {
        if ($suites === []) {
            return [[], []];
        }

        // Deletions defeat the graph on both tiers (ImpactSelection
        // widens PHP to everything for the same reason): run every JS
        // suite in full rather than trust a partial `related` set.
        if ($changed->deleted !== []) {
            return [
                $suites,
                [sprintf('Impact: %d deleted file(s) — every Vitest suite runs in full.', count($changed->deleted))],
            ];
        }

        $scoped = [];
        $notes  = [];

        foreach ($suites as $suite) {
            $directory = self::directoryOf($suite, $workingDirectory);

            $related = [];

            foreach ($changed->files as $file) {
                if (str_starts_with(WorkingDirectory::native($file), $directory . DIRECTORY_SEPARATOR)) {
                    $related[] = $file;
                }
            }

            if ($related === []) {
                $notes[] = sprintf('Impact: Vitest suite %s — no JS changes, skipped.', $suite->directory);

                continue;
            }

            $scoped[] = new VitestSuite($suite->directory, $suite->binary, $related);
            $notes[]  = sprintf(
                'Impact: Vitest suite %s — %d changed file(s), running related.',
                $suite->directory,
                count($related),
            );
        }

        return [$scoped, $notes];
    }

    /**
     * The suite's directory as an absolute, symlink-resolved path — the
     * prefix a changed file must share to belong to it.
     *
     */
    private static function directoryOf(VitestSuite $suite, WorkingDirectory $workingDirectory): string
    {
        $path = $workingDirectory->absolute($suite->directory);
        $real = realpath($path);

        return $real === false ? rtrim($path, DIRECTORY_SEPARATOR) : $real;
    }
}
