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
use LucianoPereira\Crucible\Test\TestGroup;

use function array_keys;
use function basename;
use function hash;
use function hash_file;
use function implode;
use function is_array;
use function is_file;
use function rtrim;
use function sort;
use function spl_autoload_functions;
use function str_starts_with;
use function strlen;
use function substr;

use const DIRECTORY_SEPARATOR;

/**
 * Everything but file content that a dependency answer depends on.
 *
 * {@see DependencyIndex} replays a file's resolved dependencies when its
 * bytes have not changed. That is only safe while the rest of the answer
 * is unchanged too, and three things outside the file decide it.
 *
 * The loader resolution data, because resolving a referenced class
 * name to a path is what the scan does. Remap a class in the
 * classmap — a regenerated `vendor/composer/autoload_classmap.php`
 * after a file was moved, which neither `composer.json` nor the
 * lockfile records — and the same bytes resolve elsewhere. A cache
 * keyed on content alone answers confidently from the old map, and
 * the failure mode is the invisible one: a test that should have
 * been selected is not, so it cannot fail.
 *
 * The Composer manifests, because they configure that resolution
 * before it is generated.
 *
 * And the suite configuration, which is the one that bites by mere
 * presence. A `*.pest.php` file depends on the `Pest.php` and
 * `Datasets/*.php` above it (D-033), so ADDING one changes that
 * file's dependency list without touching a byte of it. The set is
 * enumerated by the same rules the edge answers use ({@see
 * SuiteConfiguration}), and resolved the way discovery names a test
 * — production TestIds are project-relative, so a relative id is
 * joined against the root before the walk starts. The previous
 * implementation skipped that join, and its suite component was
 * therefore always empty: the pinning test stayed green only because
 * it built ids in a shape production never produces.
 *
 * Every path-shaped input is folded in relative to the project root,
 * so two checkouts of one project land on one key wherever they sit
 * (the CI cache restored into a different checkout is the normal case,
 * not the exotic one). An entry that resolves OUTSIDE the root — a
 * path repository's sibling checkout — contributes its file name and
 * a fixed marker instead of the path: the entry still moves the key
 * when it appears or disappears, while the value stays
 * machine-independent. Such a dependency is dropped by the graph
 * anyway, so its exact location cannot change an answer.
 *
 * Erring coarse is the wrong direction here. A fingerprint that is
 * too SPECIFIC only costs a cold run; one that is too general
 * returns a wrong answer silently. The payload carries a scheme tag:
 * a change to WHAT is folded in lands on a different key than every
 * index written before it, and the old files are orphaned rather
 * than read.
 */
final readonly class ImpactFingerprint
{
    /**
     * @param non-empty-string $root
     * @param list<TestGroup>  $groups
     *
     * @return non-empty-string
     */
    public static function of(string $root, array $groups): string
    {
        $root = rtrim(WorkingDirectory::native($root), '/\\');

        if ($root === '') {
            // The filesystem root (or a Windows drive) itself.
            $root = DIRECTORY_SEPARATOR;
        }

        $suite = [];

        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                $file = $test->id->file;

                // Production ids are project-relative; joining them
                // against the root is what makes the walk below see
                // the same directories the edge rules see.
                $file = WorkingDirectory::isAbsolute($file)
                    ? $file
                    : $root . '/' . $file;

                foreach (SuiteConfiguration::above($root, WorkingDirectory::native($file)) as $configuration) {
                    $suite[$configuration] = true;
                }
            }
        }

        $suite = array_keys($suite);
        sort($suite);

        $parts = ['deps:2'];

        foreach (['composer.lock', 'composer.json'] as $manifest) {
            $path = $root . '/' . $manifest;

            if (is_file($path)) {
                $hash = hash_file('xxh128', $path);

                if ($hash !== false) {
                    $parts[] = 'manifest:' . $manifest . '=' . $hash;
                }
            }
        }

        // The paths, not their contents: a Pest.php that CHANGES
        // already invalidates through its own content hash, because it
        // is itself a graph node. What content hashing cannot see is
        // one appearing or disappearing above a file that did not
        // change.
        foreach ($suite as $configuration) {
            $parts[] = 'suite:' . self::relative($root, $configuration);
        }

        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                $parts = [...$parts, ...self::loaderOf($root, $function[0])];
            }
        }

        sort($parts);

        return hash('xxh128', implode("\n", $parts));
    }

    /**
     * The parts of one registered loader's resolution a cached answer
     * depends on: the classmap, the PSR-4 and PSR-0 prefixes with
     * their fallbacks, and the flag that makes findFile() stop at the
     * classmap.
     *
     * @param non-empty-string $root
     *
     * @return list<string>
     */
    private static function loaderOf(string $root, ClassLoader $loader): array
    {
        $parts = [];

        foreach ($loader->getClassMap() as $class => $path) {
            $parts[] = 'classmap:' . $class . '=' . self::relative($root, $path);
        }

        foreach ($loader->getPrefixesPsr4() as $namespace => $paths) {
            $parts[] = 'psr4:' . $namespace . '=' . self::pathsOf($root, $paths);
        }

        foreach ($loader->getFallbackDirsPsr4() as $path) {
            $parts[] = 'psr4:*=' . self::relative($root, $path);
        }

        foreach ($loader->getPrefixes() as $namespace => $paths) {
            $parts[] = 'psr0:' . $namespace . '=' . self::pathsOf($root, $paths);
        }

        foreach ($loader->getFallbackDirs() as $path) {
            $parts[] = 'psr0:*=' . self::relative($root, $path);
        }

        if ($loader->isClassMapAuthoritative()) {
            $parts[] = 'classmap-authoritative';
        }

        return $parts;
    }

    /**
     * @param non-empty-string $root
     * @param list<string>    $paths
     *
     * @return string
     */
    private static function pathsOf(string $root, array $paths): string
    {
        $relative = [];

        foreach ($paths as $path) {
            $relative[] = self::relative($root, $path);
        }

        return implode(',', $relative);
    }

    /**
     * Portable and root-relative when the path is inside the project,
     * the file name behind a fixed marker when it is not: the value
     * must not move with the machine, and an outside entry's location
     * cannot change an answer the graph drops anyway ({@see
     * DependencyGraph::fileOf()}).
     *
     * @param non-empty-string $root native
     * @param string           $path
     *
     * @return string
     */
    private static function relative(string $root, string $path): string
    {
        $native = WorkingDirectory::native($path);
        $prefix = $root . DIRECTORY_SEPARATOR;

        if ($native === $root || str_starts_with($native, $prefix)) {
            return WorkingDirectory::portable(substr($native, strlen($prefix)));
        }

        return 'outside:' . basename(WorkingDirectory::portable($native));
    }
}
