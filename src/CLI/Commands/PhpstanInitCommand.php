<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_find;
use function array_unique;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function printf;

use const PHP_EOL;

/**
 * `crucible phpstan-init` (D-049): wire Crucible's PHPStan extension
 * into the project's analysis config — create a phpstan.neon
 * derived from the crucible configuration, or conservatively insert
 * the one includes line into the existing file. Anything
 * structurally surprising prints the line instead of guessing.
 */
final class PhpstanInitCommand
{
    public function execute(CliOptions $options, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;
            print 'phpstan-init derives the analysis paths from crucible.php — create one with crucible --init first.' . PHP_EOL;

            return 1;
        }

        $include = null;

        foreach (['vendor/cruciblephp/crucible/phpstan/extension.neon', 'phpstan/extension.neon'] as $candidate) {
            if (is_file($workingDirectory->path . '/' . $candidate)) {
                $include = $candidate;

                break;
            }
        }

        if ($include === null) {
            print 'Cannot find Crucible\'s extension.neon (expected vendor/cruciblephp/crucible/phpstan/extension.neon).' . PHP_EOL;

            return 1;
        }
        $target = array_find(['phpstan.neon', 'phpstan.neon.dist', 'phpstan.dist.neon'], fn($candidate) => is_file($workingDirectory->path . '/' . $candidate));
        if ($target === null) {
            $paths = $loaded->configuration->source->includeDirectories;
            foreach ($loaded->configuration->source->includeFiles as $file) {
                $paths[] = $file;
            }
            foreach ($loaded->configuration->testSuites as $suite) {
                foreach ([...$suite->directories, ...$suite->files] as $path) {
                    $paths[] = $path;
                }
            }
            $paths = array_values(array_unique($paths));
            file_put_contents($workingDirectory->path . '/phpstan.neon', PhpstanNeon::create($include, $paths));
            printf('Created phpstan.neon — extension wired, paths from %s.' . PHP_EOL, $loaded->path);
        } else {
            $existing = file_get_contents($workingDirectory->path . '/' . $target);

            if ($existing === false) {
                printf('Cannot read %s.' . PHP_EOL, $target);

                return 1;
            }

            if (PhpstanNeon::alreadyWired($existing, $include)) {
                printf('%s already includes the Crucible extension — nothing to do.' . PHP_EOL, $target);

                return 0;
            }

            $wired = PhpstanNeon::wire($existing, $include);

            if ($wired === null) {
                printf('%s has a shape this command will not edit. Add the include yourself:' . PHP_EOL, $target);
                print PHP_EOL . 'includes:' . PHP_EOL . '    - ' . $include . PHP_EOL;

                return 1;
            }

            file_put_contents($workingDirectory->path . '/' . $target, $wired);

            printf('Updated %s — added the Crucible extension include.' . PHP_EOL, $target);
        }

        // An opt-in feature owes the user the way in (D-041's rule).
        if (!is_file($workingDirectory->path . '/vendor/bin/phpstan')) {
            print PHP_EOL . 'PHPStan itself is not installed yet:' . PHP_EOL;
            print '  composer require --dev phpstan/phpstan' . PHP_EOL;
        }

        return 0;
    }
}
