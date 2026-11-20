<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Impact;

use Composer\Autoload\ClassLoader;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_keys;
use function array_pop;
use function explode;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function realpath;
use function rtrim;
use function spl_autoload_functions;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strtr;
use function substr;

use const DIRECTORY_SEPARATOR;

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

        $this->projectRoot = rtrim(WorkingDirectory::native($real === false ? $projectRoot : $real), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
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
        $real = $real === false ? WorkingDirectory::native($file) : $real;

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

            if ($this->inProject($real)) {
                return $real;
            }
        }

        return null;
    }

    /**
     * The project files a changed file may now hide from the autoloader.
     *
     * A loader answers for a class by trying its directories in order
     * until a file exists, so a file that appears ahead of the one a
     * warm index recorded takes the class over without a byte of the
     * recorded file, a manifest or the classmap moving. The change set
     * holds the new file, but no recorded closure does; the file it
     * shadows is in the closures. This names that file, so selection can
     * treat the change as touching it.
     *
     * The work is per change, not per source file: the class names the
     * file would declare under every prefix it sits in, then every other
     * place the registered loaders would look for those names. Erring
     * wide is safe here - a candidate no closure contains selects
     * nothing - and erring narrow is the silent false green this exists
     * to close.
     *
     * @param non-empty-string $file absolute path
     *
     * @return list<non-empty-string> absolute paths, $file excluded
     */
    public function shadowedBy(string $file): array
    {
        $real = realpath($file);
        $file = $real === false ? WorkingDirectory::native($file) : $real;

        if (!str_ends_with($file, '.php')) {
            return [];
        }

        $classes = [];

        foreach ($this->loaders as $loader) {
            foreach ($this->prefixDirectories($loader) as [$prefix, $directories]) {
                foreach ($directories as $directory) {
                    $relative = $this->below($file, $directory);

                    if ($relative !== null) {
                        $classes[$prefix . strtr(substr($relative, 0, -4), DIRECTORY_SEPARATOR, '\\')] = true;
                    }
                }
            }
        }

        $shadowed = [];

        foreach (array_keys($classes) as $class) {
            foreach ($this->candidatesOf($class) as $candidate) {
                $found = realpath($candidate);

                if ($found !== false && $found !== $file && $this->inProject($found)) {
                    $shadowed[$found] = true;
                }
            }
        }

        return array_keys($shadowed);
    }

    /**
     * Every file the registered loaders could answer $class with: the
     * classmap entry, and the path under each PSR-4 and PSR-0 directory
     * whose prefix the class carries, fallbacks included.
     *
     * @return list<string>
     */
    private function candidatesOf(string $class): array
    {
        $candidates = [];

        foreach ($this->loaders as $loader) {
            $mapped = $loader->getClassMap()[$class] ?? null;

            if ($mapped !== null) {
                $candidates[] = $mapped;
            }

            foreach ($this->prefixDirectories($loader) as [$prefix, $directories]) {
                if ($prefix !== '' && !str_starts_with($class, $prefix)) {
                    continue;
                }

                $path = strtr(substr($class, strlen($prefix)), '\\', DIRECTORY_SEPARATOR) . '.php';

                foreach ($directories as $directory) {
                    $candidates[] = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $path;
                }
            }
        }

        return $candidates;
    }

    /**
     * One loader's prefix-to-directories lists, the class-name part each
     * directory stands for first: a PSR-4 prefix stands for its
     * namespace, a PSR-4 fallback for nothing, and a PSR-0 directory for
     * nothing either, because PSR-0 keeps the whole namespace in the
     * path. An authoritative classmap consults none of them.
     *
     * @return list<array{string, list<string>}>
     */
    private function prefixDirectories(ClassLoader $loader): array
    {
        if ($loader->isClassMapAuthoritative()) {
            return [];
        }

        $lists = [];

        foreach ($loader->getPrefixesPsr4() as $namespace => $directories) {
            $lists[] = [$namespace, $directories];
        }

        $lists[] = ['', $loader->getFallbackDirsPsr4()];

        foreach ($loader->getPrefixes() as $directories) {
            $lists[] = ['', $directories];
        }

        $lists[] = ['', $loader->getFallbackDirs()];

        return $lists;
    }

    /**
     * $file relative to $directory, native separators, or null when it
     * does not sit below it.
     */
    private function below(string $file, string $directory): ?string
    {
        $real = realpath($directory);

        if ($real === false) {
            return null;
        }

        $base = rtrim($real, '/\\') . DIRECTORY_SEPARATOR;

        return str_starts_with($file, $base) ? substr($file, strlen($base)) : null;
    }

    /**
     * Inside the project and outside every vendor directory. 'vendor' is
     * matched as a path SEGMENT anywhere, not only at the root: a
     * vendored tool or a nested package carries its own vendor/, and a
     * class resolved there is still someone else's code — ✓ found
     * 2026-09-16 when phpcpd-main/ gained symfony/polyfill-php80, whose
     * PhpToken stub entered the graph through a root-only check.
     */
    private function inProject(string $real): bool
    {
        return str_starts_with($real, $this->projectRoot)
            && !in_array('vendor', explode(DIRECTORY_SEPARATOR, substr($real, strlen($this->projectRoot))), true);
    }

    /**
     * The suite configuration a Pest-family test file's discovery loads
     * from its own directory upwards: `Pest.php` scopes and shared
     * `Datasets/*.php` (D-033).
     *
     * Public because selection also needs it at the root of a group's
     * closure, where no index entry can answer for it — a `*Test.php`
     * file written in Pest has the same ancestors as an explicit
     * `.pest.php` one.
     *
     * Delegates to the one enumeration every consumer shares ({@see
     * SuiteConfiguration}), so the edge answer, the selection root and
     * the index's fingerprint can never walk the tree differently.
     *
     * @param non-empty-string $file
     *
     * @return list<non-empty-string> absolute paths
     */
    public function suiteConfigurationAbove(string $file): array
    {
        return SuiteConfiguration::above($this->projectRoot, $file);
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
