<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Impact;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Vitest\VitestSuite;

use function array_fill_keys;
use function array_keys;
use function basename;
use function count;
use function dirname;
use function in_array;
use function pathinfo;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * The JavaScript tier of impact selection (D-080), symmetric to
 * {@see ImpactSelection} for PHP. A configured Vitest suite is narrowed
 * to the change set: the changed files that can reach it become a
 * `vitest related <files>` argument, and Vitest's own module graph then
 * decides which JS tests re-run — Crucible orchestrates rather than
 * reimplements the JS dependency walk (the D-079 principle).
 *
 * Three changes do not narrow:
 *
 * - a deletion widens every suite to a full run (a deleted file's
 *   dependents are invisible to the graph);
 * - a package, lockfile or Vitest/Vite/TypeScript/JavaScript
 *   configuration applicable to a suite — inside its directory, or
 *   above it (a workspace-level file) — runs that suite in full, because
 *   no module edge can express what a dependency bump or a changed
 *   resolver does;
 * - a module outside every suite root is a shared file (`resources/js/
 *   shared/format.ts` imported by two suites). It is passed to every
 *   suite's `related`, and Vitest's graph selects the suites that
 *   actually import it; measured on Vitest 5.1.0, a suite whose graph
 *   does not contain it exits 0 with no affected files.
 *
 * An ordinary in-root source change keeps the `related` path, and a
 * change under no suite root that is neither a module nor one of those
 * configurations (a PHP file, a README) leaves every suite out. A
 * dropped suite is named, never silently skipped (F-26).
 */
final readonly class VitestImpact
{
    /**
     * Package manifests and lockfiles: a dependency bump changes what
     * the tests import, whatever module they name.
     *
     * @var list<non-empty-string>
     */
    private const array MANIFESTS = [
        'package.json', 'package-lock.json', 'npm-shrinkwrap.json',
        'yarn.lock', 'pnpm-lock.yaml', 'bun.lock', 'bun.lockb',
    ];

    /**
     * The Vite pipeline's own extensions. A changed one is a module a
     * suite outside its root may import, so it is offered to `related`
     * rather than dropped.
     *
     * @var list<non-empty-string>
     */
    private const array MODULE_EXTENSIONS = [
        'js', 'jsx', 'ts', 'tsx', 'mjs', 'cjs', 'mts', 'cts',
        'vue', 'svelte', 'astro', 'css', 'scss', 'sass', 'less',
    ];

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

        $roots = [];

        foreach ($suites as $index => $suite) {
            $roots[$index] = self::directoryOf($suite, $workingDirectory);
        }

        /** @var array<int, list<non-empty-string>> $inRoot */
        $inRoot = array_fill_keys(array_keys($suites), []);
        /** @var list<non-empty-string> $shared */
        $shared = [];

        foreach ($changed->files as $file) {
            $absolute = self::canonical($file);
            $inside   = false;

            // Every suite root containing the file: an inner suite nested
            // in another belongs to both, and both must answer for it.
            foreach ($roots as $index => $root) {
                if (self::within($absolute, $root)) {
                    $inRoot[$index][] = $file;
                    $inside           = true;
                }
            }

            if (!$inside && !self::isEnvironment($absolute) && self::isModule($absolute)) {
                $shared[] = $file;
            }
        }

        $scoped = [];
        $notes  = [];

        foreach ($suites as $index => $suite) {
            $directory = $roots[$index];
            $full      = null;

            foreach ($changed->files as $file) {
                $absolute = self::canonical($file);

                if (self::isEnvironment($absolute) && self::atOrAbove($absolute, $directory)) {
                    $full = $absolute;

                    break;
                }
            }

            if ($full !== null) {
                $scoped[] = $suite;
                $notes[]  = sprintf(
                    'Impact: Vitest suite %s — %s configures it, running the whole suite.',
                    $suite->directory,
                    $workingDirectory->relative($full),
                );

                continue;
            }

            $related = [...$inRoot[$index], ...$shared];

            if ($related === []) {
                $notes[] = sprintf('Impact: Vitest suite %s — no JS changes, skipped.', $suite->directory);

                continue;
            }

            $scoped[] = $suite->relatedTo($related);
            $notes[]  = $inRoot[$index] === []
                ? sprintf(
                    'Impact: Vitest suite %s — %d shared change(s) outside its root, asking Vitest which tests they reach.',
                    $suite->directory,
                    count($shared),
                )
                : sprintf(
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
     */
    private static function directoryOf(VitestSuite $suite, WorkingDirectory $workingDirectory): string
    {
        $path = $workingDirectory->absolute($suite->directory);
        $real = realpath($path);

        return $real === false ? rtrim(WorkingDirectory::native($path), DIRECTORY_SEPARATOR) : $real;
    }

    /**
     * The changed file in the same form as a suite directory: absolute,
     * symlink-resolved, native separators.
     *
     * @param non-empty-string $file
     *
     * @return non-empty-string
     */
    private static function canonical(string $file): string
    {
        $real = realpath($file);

        if ($real !== false) {
            return $real;
        }

        $native = rtrim(WorkingDirectory::native($file), DIRECTORY_SEPARATOR);

        return $native === '' ? WorkingDirectory::native($file) : $native;
    }

    /** Whether the file sits under the directory (not at it). */
    private static function within(string $file, string $directory): bool
    {
        return str_starts_with($file, $directory . DIRECTORY_SEPARATOR);
    }

    /**
     * Whether the file's directory is the suite's, or one of its
     * ancestors: a root lockfile and a workspace `vitest.workspace.ts`
     * apply to every package below them, a package's own configuration
     * only to itself.
     */
    private static function atOrAbove(string $file, string $directory): bool
    {
        $home = WorkingDirectory::native(dirname($file));

        if ($home === $directory) {
            return true;
        }

        // A file at a drive root gives `C:\` from dirname(); trimming it
        // would produce `C:` and match no suite.
        return str_starts_with($directory, rtrim($home, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);
    }

    /**
     * Whether a change can change how the suite resolves or runs its
     * modules, without being a module itself: the dependency manifests
     * and lockfiles, Vitest's and Vite's configuration, the two
     * TypeScript configurations (and their project variants), and
     * `.env` files Vite reads.
     */
    private static function isEnvironment(string $path): bool
    {
        $name = basename($path);

        if (in_array($name, self::MANIFESTS, true)) {
            return true;
        }

        return preg_match('/^(?:vitest|vite)\.config\.[cm]?[jt]s$/', $name) === 1
            || preg_match('/^vitest\.workspace\.[cm]?[jt]s$/', $name) === 1
            || preg_match('/^tsconfig(?:\..+)?\.json$/', $name) === 1
            || $name === 'jsconfig.json'
            || preg_match('/^\.env(?:\..+)?$/', $name) === 1;
    }

    /** Whether the file is a module the Vite pipeline could load. */
    private static function isModule(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::MODULE_EXTENSIONS, true);
    }
}
