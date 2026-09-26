<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use FilesystemIterator;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\CLI\Phpstan;
use LucianoPereira\Crucible\CLI\PhpstanNeon;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_unique;
use function array_values;
use function file_get_contents;
use function file_put_contents;
use function is_dir;
use function is_file;
use function is_string;
use function printf;
use function str_ends_with;
use function str_replace;

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

        $include = Phpstan::extension($workingDirectory);

        if ($include === null) {
            print 'Cannot find Crucible\'s extension.neon (expected vendor/cruciblephp/crucible/phpstan/extension.neon).' . PHP_EOL;

            return 1;
        }
        $target = Phpstan::projectConfiguration($workingDirectory);

        // Crucible declares its extension to phpstan/extension-installer
        // (D-127), which then loads it for every analysis. An include
        // written as well loads it twice, and PHPStan refuses to run.
        $installer = PhpstanNeon::installerLoadsExtension($workingDirectory);

        if ($installer) {
            $existing = $target === null ? false : file_get_contents($workingDirectory->path . '/' . $target);

            if (is_string($existing) && PhpstanNeon::alreadyWired($existing, $include)) {
                printf('phpstan/extension-installer already loads the Crucible extension, and %s includes it too.' . PHP_EOL, $target);
                print 'PHPStan refuses a file included twice. Remove this line from ' . $target . ':' . PHP_EOL;
                print PHP_EOL . '    - ' . $include . PHP_EOL;

                return 1;
            }

            if ($target !== null) {
                print 'phpstan/extension-installer already loads the Crucible extension — nothing to add.' . PHP_EOL;

                return $this->wireDialect($loaded->configuration, $workingDirectory, $target, $include);
            }
        }

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
            $paths  = array_values(array_unique($paths));
            $target = 'phpstan.neon';
            file_put_contents($workingDirectory->path . '/' . $target, PhpstanNeon::create($installer ? null : $include, $paths));
            printf(
                'Created phpstan.neon — %s, paths from %s.' . PHP_EOL,
                $installer ? 'the installer wires the extension' : 'extension wired',
                $loaded->path,
            );
        } else {
            $existing = file_get_contents($workingDirectory->path . '/' . $target);

            if ($existing === false) {
                printf('Cannot read %s.' . PHP_EOL, $target);

                return 1;
            }

            if (PhpstanNeon::alreadyWired($existing, $include)) {
                printf('%s already includes the Crucible extension — nothing to do.' . PHP_EOL, $target);

                return $this->wireDialect($loaded->configuration, $workingDirectory, $target, $include);
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

        return $this->wireDialect($loaded->configuration, $workingDirectory, $target, $include);
    }

    /**
     * check(), property() and table() are scanned by crucible-dialect.neon,
     * not by the extension every project gets (D-128). A suite that writes
     * the dialect — it holds *.crucible.php files — needs that include
     * too; the installer never adds it.
     *
     * @param non-empty-string $target
     * @param non-empty-string $include the extension.neon include in use
     */
    private function wireDialect(Configuration $configuration, WorkingDirectory $workingDirectory, string $target, string $include): int
    {
        if (!$this->writesDialect($configuration, $workingDirectory)) {
            return 0;
        }

        $dialect  = str_replace('extension.neon', 'crucible-dialect.neon', $include);
        $existing = file_get_contents($workingDirectory->path . '/' . $target);

        if ($existing === false) {
            printf('Cannot read %s.' . PHP_EOL, $target);

            return 1;
        }

        if (PhpstanNeon::alreadyWired($existing, $dialect)) {
            return 0;
        }

        $wired = PhpstanNeon::wire($existing, $dialect);

        if ($wired === null) {
            printf('%s has a shape this command will not edit. Your suite writes the crucible dialect; add:' . PHP_EOL, $target);
            print PHP_EOL . 'includes:' . PHP_EOL . '    - ' . $dialect . PHP_EOL;

            return 1;
        }

        file_put_contents($workingDirectory->path . '/' . $target, $wired);
        printf('Updated %s — the suite writes the crucible dialect, so check()/property()/table() are declared too.' . PHP_EOL, $target);

        return 0;
    }

    /** Whether any configured test directory holds a *.crucible.php file. */
    private function writesDialect(Configuration $configuration, WorkingDirectory $workingDirectory): bool
    {
        foreach ($configuration->testSuites as $suite) {
            foreach ($suite->directories as $directory) {
                $absolute = $workingDirectory->absolute($directory);

                if (!is_dir($absolute)) {
                    continue;
                }

                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.crucible.php')) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
