<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Impact;

use function dirname;
use function glob;
use function is_file;
use function rtrim;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;

/**
 * The suite configuration discovery loads above a file: every ancestor
 * `Pest.php` and the `Datasets/*.php` beside them (D-033).
 *
 * One implementation serves the two consumers that must never
 * disagree. The edge rules add these files to a Pest-family file's
 * dependencies ({@see DependencyGraph}); the dependency index's
 * fingerprint folds their mere PRESENCE into its key, because a
 * `Pest.php` appearing above an unchanged file changes that file's
 * answer without touching a byte of it ({@see ImpactFingerprint}).
 * While the two walked the tree independently they drifted - the
 * fingerprint joined paths with the wrong separator and never saw any
 * configuration at all, so a warm index kept replaying answers the
 * graph's own rules no longer produced.
 *
 * Paths are the platform's own separator throughout, root and files
 * alike: on Windows a `/` where the filesystem says `\` makes every
 * "is this directory inside the project" comparison answer no, which
 * is how both the fingerprint's suite walk and the index's persistence
 * quietly produced nothing.
 */
final readonly class SuiteConfiguration
{
    /**
     * The configuration above $file, from its own directory up to
     * $root, as many times as discovery would load it: a `Pest.php`
     * scopes its own directory and every directory beneath it.
     *
     * @param string $root the project root, native separators
     * @param string $file inside the root, native separators
     *
     * @return list<string> absolute native paths
     */
    public static function above(string $root, string $file): array
    {
        $found     = [];
        $directory = dirname($file);
        $root      = rtrim($root, DIRECTORY_SEPARATOR);

        if ($root === '') {
            // The filesystem root (or a Windows drive) itself: dirname()
            // answers it back, and the guard below would never fire.
            $root = DIRECTORY_SEPARATOR;
        }

        while (str_starts_with($directory, $root)) {
            $pest = $directory . DIRECTORY_SEPARATOR . 'Pest.php';

            if (is_file($pest)) {
                $found[] = $pest;
            }

            $datasets = glob($directory . DIRECTORY_SEPARATOR . 'Datasets' . DIRECTORY_SEPARATOR . '*.php');

            foreach ($datasets === false ? [] : $datasets as $dataset) {
                if ($dataset !== '') {
                    $found[] = $dataset;
                }
            }

            if ($directory === $root) {
                break;
            }

            $next = dirname($directory);

            // A project rooted at the filesystem root (or a Windows
            // drive) has no parent: dirname() answers the directory
            // itself there, which would loop forever.
            if ($next === $directory) {
                break;
            }

            $directory = $next;
        }

        return $found;
    }
}
