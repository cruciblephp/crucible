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
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function basename;
use function explode;
use function hash;
use function hash_file;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function ksort;
use function rtrim;
use function sort;
use function spl_autoload_functions;
use function str_ends_with;
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
 * Resolution is more than the classmap, and both remaining blind
 * spots bite the same way. findFile() answers for a namespace by
 * trying each directory of the prefix in order until a file EXISTS,
 * so a file that APPEARS in the priority directory shadows the one
 * a warm index recorded, with no configuration change at all — the
 * project's prefix directory listings reach the key for that. And
 * the loaders are a stack: two of them can both declare one class
 * and the first registered wins, so every part carries its loader's
 * registration position, which a flat sorted list used to erase.
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

        $parts = ['deps:3'];

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

        // Registration order, not alphabetical: two loaders can both
        // declare one class, and the first registered wins. Folding
        // every part into one sorted list erased that - the same
        // strings sorted the same whichever loader came first - so
        // each loader carries its position into the payload. The
        // manifest and suite parts above are sets and already
        // canonical; only the loaders are a stack.
        $ordinal = 0;

        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                $parts = [...$parts, ...self::loaderOf($root, $function[0], $ordinal++)];
            }
        }

        return hash('xxh128', implode("\n", $parts));
    }

    /**
     * The parts of one registered loader's resolution a cached answer
     * depends on: the classmap, the PSR-4 and PSR-0 prefixes with
     * their fallbacks, the flag that makes findFile() stop at the
     * classmap - and the file listing of every prefix directory inside
     * the project.
     *
     * The ordinal is not cosmetic. Two loaders can both declare one
     * class, and the first registered wins: folding every loader's
     * parts into one sorted list erased that, because both loaders
     * contributed the same sorted strings whichever came first. Each
     * part carries the loader's registration position now, and the
     * sequence reaches the key.
     *
     * The listings are why the prefix directories reach the key at
     * all. findFile() answers for a namespace by trying each directory
     * of the prefix in order until a file EXISTS - resolution reads
     * the directory contents, and no configuration change happens when
     * a file simply appears in the priority directory to shadow the
     * one a warm index recorded. The listing hashes the names of every
     * `.php` file under the directory, relative and portable, so two
     * checkouts hash the same tree and a warm index on either answers
     * from the same resolution. Only directories inside the project
     * are listed: a resolution that lands in vendor/ or outside the
     * root is dropped by the graph anyway, and vendor content is a
     * dependency bump - an environment change upstream answers with a
     * full run ({@see DependencyGraph}).
     *
     * @param non-empty-string $root
     *
     * @return list<string>
     */
    private static function loaderOf(string $root, ClassLoader $loader, int $ordinal): array
    {
        $prefix  = 'loader' . $ordinal . ':';
        $parts   = [];
        $scanned = [];

        // Within ONE loader the classmap is a lookup table - set
        // semantics - so the entries fold in sorted and a regeneration
        // that merely reordered the array keeps the key. The ordinal
        // already carries what matters across loaders.
        $classMap = $loader->getClassMap();
        ksort($classMap);

        foreach ($classMap as $class => $path) {
            $parts[] = $prefix . 'classmap:' . $class . '=' . self::relative($root, $path);
        }

        // Prefixes are exact-match lookups - set semantics on the
        // namespaces - but the directories WITHIN one prefix are a
        // priority list: first to hold the file wins, so their order
        // folds in as given.
        $psr4 = $loader->getPrefixesPsr4();
        ksort($psr4);

        foreach ($psr4 as $namespace => $paths) {
            $parts[]   = $prefix . 'psr4:' . $namespace . '=' . self::pathsOf($root, $paths);
            $scanned[] = $paths;
        }

        $fallbackPsr4 = $loader->getFallbackDirsPsr4();

        if ($fallbackPsr4 !== []) {
            $parts[]   = $prefix . 'psr4:*=' . self::pathsOf($root, $fallbackPsr4);
            $scanned[] = $fallbackPsr4;
        }

        $psr0 = $loader->getPrefixes();
        ksort($psr0);

        foreach ($psr0 as $namespace => $paths) {
            $parts[]   = $prefix . 'psr0:' . $namespace . '=' . self::pathsOf($root, $paths);
            $scanned[] = $paths;
        }

        $fallbackPsr0 = $loader->getFallbackDirs();

        if ($fallbackPsr0 !== []) {
            $parts[]   = $prefix . 'psr0:*=' . self::pathsOf($root, $fallbackPsr0);
            $scanned[] = $fallbackPsr0;
        }

        if ($loader->isClassMapAuthoritative()) {
            $parts[] = $prefix . 'classmap-authoritative';
        }

        $seen = [];

        foreach ($scanned as $paths) {
            foreach ($paths as $directory) {
                if (isset($seen[$directory])) {
                    continue;
                }

                $listing = self::listingPart($root, $prefix, $directory);

                if ($listing !== null) {
                    $parts[]          = $listing;
                    $seen[$directory] = true;
                }
            }
        }

        return $parts;
    }

    /**
     * The listing part for one prefix directory, or null when the
     * directory cannot change an answer: outside the project root, or
     * under a vendor segment - the graph drops those resolutions and
     * vendor content is an environment change.
     *
     * @param non-empty-string $root    native
     * @param string           $prefix  the loader ordinal's prefix
     * @param string           $directory
     *
     * @return ?string
     */
    private static function listingPart(string $root, string $prefix, string $directory): ?string
    {
        $native = WorkingDirectory::native($directory);

        if ($native === $root || !str_starts_with($native, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $relative = substr($native, strlen($root . DIRECTORY_SEPARATOR));

        if (in_array('vendor', explode(DIRECTORY_SEPARATOR, $relative), true)) {
            return null;
        }

        return $prefix . 'listing:' . WorkingDirectory::portable($relative) . '=' . self::listingHash($native);
    }

    /**
     * Every `.php` file name under $directory, relative to it, sorted
     * and folded portable: the input findFile() actually consults. A
     * missing directory hashes like an empty one - neither can win a
     * resolution - and the first file to appear there moves the key.
     *
     * @param non-empty-string $directory native
     *
     * @return non-empty-string
     */
    private static function listingHash(string $directory): string
    {
        $names = [];

        if (is_dir($directory)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            );

            foreach ($iterator as $entry) {
                if ($entry instanceof SplFileInfo && $entry->isFile() && str_ends_with($entry->getPathname(), '.php')) {
                    $name = substr($entry->getPathname(), strlen($directory) + 1);

                    if ($name !== '') {
                        $names[] = WorkingDirectory::portable($name);
                    }
                }
            }
        }

        sort($names);

        return hash('xxh128', implode("\n", $names));
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
