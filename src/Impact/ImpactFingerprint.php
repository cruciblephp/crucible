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
use LucianoPereira\Crucible\Test\TestGroup;

use function array_keys;
use function dirname;
use function glob;
use function hash;
use function implode;
use function is_array;
use function is_file;
use function rtrim;
use function sort;
use function spl_autoload_functions;
use function str_starts_with;

/**
 * Everything but file content that a dependency answer depends on.
 *
 * {@see DependencyIndex} replays a file's resolved dependencies when its
 * bytes have not changed. That is only safe while the rest of the answer
 * is unchanged too, and two things outside the file decide it.
 *
 * The autoload map, because resolving a referenced class name to a path
 * is what the scan does; remap a namespace and the same bytes resolve
 * elsewhere.
 *
 * And the suite configuration, which is the one that bites. A
 * `*.pest.php` file depends on the `Pest.php` and `Datasets/*.php`
 * above it (D-033), so **adding** one changes that file's dependency
 * list without touching a byte of it. A cache keyed on content alone
 * would answer confidently from stale data, and the failure mode is the
 * invisible one: a test that should have been selected is not, so it
 * cannot fail.
 *
 * Erring coarse is the wrong direction here. A fingerprint that is too
 * SPECIFIC only costs a cold run; one that is too general returns a
 * wrong answer silently, which is why the whole set of suite
 * configuration files is folded in rather than a count of them.
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
        $root = rtrim($root, '/');

        $directories = [];

        foreach ($groups as $group) {
            foreach ($group->tests as $test) {
                $directories[dirname($test->id->file)] = true;
            }
        }

        $suite = [];

        foreach (array_keys($directories) as $directory) {
            foreach (self::configurationAbove($directory, $root) as $file) {
                $suite[$file] = true;
            }
        }

        $suite = array_keys($suite);
        sort($suite);

        $prefixes = [];

        foreach (spl_autoload_functions() as $function) {
            if (is_array($function) && $function[0] instanceof ClassLoader) {
                foreach ($function[0]->getPrefixesPsr4() as $namespace => $paths) {
                    $prefixes[] = $namespace . '=' . implode(',', $paths);
                }
            }
        }

        sort($prefixes);

        return hash('xxh128', implode("\n", $prefixes) . "\n--\n" . implode("\n", $suite));
    }

    /**
     * @return list<string>
     */
    private static function configurationAbove(string $directory, string $root): array
    {
        $found = [];

        while (str_starts_with($directory, $root)) {
            if (is_file($directory . '/Pest.php')) {
                $found[] = $directory . '/Pest.php';
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
}
