<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Impact;

use Composer\Autoload\ClassLoader;

use function array_keys;
use function array_pop;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function in_array;
use function is_array;
use function is_file;
use function realpath;
use function rtrim;
use function spl_autoload_functions;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The file-level dependency graph for impact selection (growth G3,
 * Ekstazi's granularity): file → the project files it references,
 * derived lazily from static analysis (the anti-haste-map lesson —
 * nothing is scanned until a closure walks into it) and resolved
 * through the Composer autoloaders, which are the authority on
 * name → file. Vendor code is not tracked: a dependency bump is an
 * environment change, handled upstream as a full run.
 *
 * A second edge source — per-test covered-files maps — plugs in here
 * when the coverage phase lands (RESEARCH.md §2).
 */
final class DependencyGraph
{
    /** @var array<string, list<string>> */
    private array $dependencies = [];

    /** @var list<ClassLoader> */
    private readonly array $loaders;

    /** @var non-empty-string */
    private readonly string $projectRoot;

    /**
     * @param non-empty-string            $projectRoot
     * @param array<string, list<string>> $observedEdges coverage-observed dependencies (D-041),
     *                                                   absolute file => absolute files it executed —
     *                                                   what static analysis cannot see, execution can
     */
    public function __construct(
        string $projectRoot,
        private readonly ReferenceScanner $scanner = new ReferenceScanner(),
        private readonly array $observedEdges = [],
        private readonly ?DependencyIndex $index = null,
    ) {
        $this->index?->load();

        $real = realpath($projectRoot);

        $this->projectRoot = rtrim($real === false ? $projectRoot : $real, '/') . '/';
        $this->loaders     = $this->composerLoaders();
    }

    /**
     * Every project file reachable from $file, including itself —
     * the set whose intersection with the changed files decides
     * whether $file's tests run.
     *
     * @param non-empty-string $file absolute path
     *
     * @return array<string, true> keyed by absolute path
     */
    public function closureOf(string $file): array
    {
        $real = realpath($file);
        $real = $real === false ? $file : $real;

        /** @var array<string, true> $visited */
        $visited = [];

        // Observed edges apply one hop from the root only: coverage
        // already recorded everything the root's tests executed —
        // transitively — so expanding them again from inner nodes
        // would conflate "this test runs file X" with "file X's own
        // inline tests run Y" (a file can be both production code
        // and a test carrier) and over-select wildly.
        $stack = [$real, ...($this->observedEdges[$real] ?? [])];

        while ($stack !== []) {
            $current = array_pop($stack);

            if (isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($this->dependenciesOf($current) as $dependency) {
                if (!isset($visited[$dependency])) {
                    $stack[] = $dependency;
                }
            }
        }

        return $visited;
    }

    /**
     * @return list<string> absolute paths, memoized per file
     */
    private function dependenciesOf(string $file): array
    {
        if (isset($this->dependencies[$file])) {
            return $this->dependencies[$file];
        }

        $reused = $this->index?->reuse($file);

        if ($reused !== null) {
            return $this->dependencies[$file] = $reused;
        }

        $source = is_file($file) ? file_get_contents($file) : false;

        /** @var array<string, true> $found */
        $found = [];

        if ($source !== false) {
            foreach ($this->scanner->referencesIn($source) as $candidate) {
                $resolved = $this->fileOf($candidate);

                if ($resolved !== null && $resolved !== $file) {
                    $found[$resolved] = true;
                }
            }
        }

        // Pest-family files also depend on the suite configuration
        // above them: Pest.php scopes and shared Datasets/*.php are
        // loaded per ancestor directory (D-033), so a change there
        // must re-run the files beneath.
        if (str_ends_with($file, '.pest.php') || str_ends_with($file, '.crucible.php')) {
            foreach ($this->suiteConfigurationAbove($file) as $configuration) {
                if ($configuration !== $file) {
                    $found[$configuration] = true;
                }
            }
        }

        $resolved = array_keys($found);
        $this->index?->record($file, $resolved);

        return $this->dependencies[$file] = $resolved;
    }

    /** Persist what this run resolved, when an index is in use. */
    public function persist(): void
    {
        $this->index?->save();
    }

    /**
     * @return ?string absolute path inside the project (vendor excluded)
     */
    private function fileOf(string $class): ?string
    {
        foreach ($this->loaders as $loader) {
            $file = $loader->findFile($class);

            if ($file === false) {
                continue;
            }

            $real = realpath($file);

            if ($real === false) {
                continue;
            }

            // 'vendor' as a path SEGMENT anywhere, not only at the root.
            // A vendored tool or a nested package carries its own
            // vendor/, and a class resolved there is still someone
            // else's code — ✓ found 2026-09-16 when phpcpd-main/ gained
            // symfony/polyfill-php80, whose PhpToken stub entered the
            // graph through a root-only check.
            if (str_starts_with($real, $this->projectRoot)
                && !in_array('vendor', explode('/', substr($real, strlen($this->projectRoot))), true)
            ) {
                return $real;
            }
        }

        return null;
    }

    /**
     * @param non-empty-string $file
     *
     * @return list<string> absolute paths
     */
    private function suiteConfigurationAbove(string $file): array
    {
        $found     = [];
        $directory = dirname($file);
        $root      = rtrim($this->projectRoot, '/');

        while (str_starts_with($directory, $root)) {
            $pest = $directory . '/Pest.php';

            if (is_file($pest)) {
                $found[] = $pest;
            }

            $datasets = glob($directory . '/Datasets/*.php');

            foreach ($datasets === false ? [] : $datasets as $dataset) {
                $found[] = $dataset;
            }

            if ($directory === $root) {
                break;
            }

            $directory = dirname($directory);
        }

        return $found;
    }

    /**
     * @return list<ClassLoader>
     */
    private function composerLoaders(): array
    {
        $loaders = [];

        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                $loaders[] = $function[0];
            }
        }

        return $loaders;
    }
}
