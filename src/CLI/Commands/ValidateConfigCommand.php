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
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function count;
use function is_dir;
use function is_file;
use function printf;
use function sprintf;

use const PHP_EOL;

/**
 * The spec's --validate-configuration, against the configuration
 * Crucible actually has. There is no XML and so no schema to check a
 * document against; what a PHP configuration can be wrong about is
 * different and more useful — it may fail to load, or name a directory
 * that is not there.
 *
 * A configuration that loads and whose every declared path exists is
 * valid; anything else is reported with the path that is missing, and
 * exits non-zero so CI can hold the line.
 */
final class ValidateConfigCommand
{
    public function execute(CliOptions $options, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            // A configuration that cannot be loaded is the one kind of
            // invalid a PHP configuration file really has.
            printf('Invalid: %s' . PHP_EOL, $e->getMessage());

            return 1;
        }

        $configuration = $loaded->configuration;
        $problems      = [];

        printf('Configuration: %s' . PHP_EOL . PHP_EOL, $loaded->path);

        if ($configuration->testSuites === []) {
            $problems[] = 'No test suite is configured — add ->testSuite(name, directory).';
        }

        foreach ($configuration->testSuites as $suite) {
            foreach ($suite->directories as $directory) {
                if (!is_dir($workingDirectory->absolute($directory))) {
                    $problems[] = sprintf('Test suite "%s": directory "%s" does not exist.', $suite->name, $directory);
                }
            }

            foreach ($suite->files as $file) {
                if (!is_file($workingDirectory->absolute($file))) {
                    $problems[] = sprintf('Test suite "%s": file "%s" does not exist.', $suite->name, $file);
                }
            }
        }

        if ($configuration->bootstrap !== null && !is_file($workingDirectory->absolute($configuration->bootstrap))) {
            $problems[] = sprintf('Bootstrap "%s" does not exist.', $configuration->bootstrap);
        }

        foreach ($configuration->source->includeDirectories as $directory) {
            if (!is_dir($workingDirectory->absolute($directory))) {
                $problems[] = sprintf('Source include "%s" does not exist.', $directory);
            }
        }

        foreach ($configuration->source->includeFiles as $file) {
            if (!is_file($workingDirectory->absolute($file))) {
                $problems[] = sprintf('Source include "%s" does not exist.', $file);
            }
        }

        if ($problems === []) {
            printf(
                'Valid: %d test suite(s), %d source include(s).' . PHP_EOL,
                count($configuration->testSuites),
                count($configuration->source->includeDirectories) + count($configuration->source->includeFiles),
            );

            return 0;
        }

        foreach ($problems as $problem) {
            print '  - ' . $problem . PHP_EOL;
        }

        printf(PHP_EOL . '%d problem(s).' . PHP_EOL, count($problems));

        return 1;
    }
}
