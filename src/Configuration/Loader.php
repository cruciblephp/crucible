<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

use LucianoPereira\Crucible\Exceptions\ConfigurationException;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_any;
use function get_debug_type;
use function is_file;
use function sprintf;

/**
 * Locates and loads a project's crucible.php configuration file.
 *
 * A PHP configuration file needs no parser, no schema validator, and
 * no format migrator: loading is a require and the type check below.
 * The PHP type system is the schema.
 */
final readonly class Loader
{
    private const array DEFAULT_FILE_NAMES = ['crucible.php', 'crucible.dist.php'];

    /**
     * @param ?non-empty-string $explicitPath     a path given via --configuration
     */
    public function load(WorkingDirectory $workingDirectory, ?string $explicitPath = null): LoadedConfiguration
    {
        $path = $this->locate($workingDirectory, $explicitPath);

        $result = require $path;

        if ($result instanceof Builder) {
            $result = $result->build();
        }

        if (!$result instanceof Configuration) {
            throw new ConfigurationException(
                sprintf(
                    '%s must return a configuration (use "return Crucible::configure()->...;"), got %s.',
                    $path,
                    get_debug_type($result),
                ),
            );
        }

        return new LoadedConfiguration($result, $path);
    }

    public function exists(WorkingDirectory $workingDirectory): bool
    {
        return array_any(self::DEFAULT_FILE_NAMES, fn($name) => is_file($workingDirectory->path . '/' . $name));
    }

    /**
     * @param ?non-empty-string $explicitPath
     *
     * @return non-empty-string
     */
    private function locate(WorkingDirectory $workingDirectory, ?string $explicitPath): string
    {
        if ($explicitPath !== null) {
            if (!is_file($explicitPath)) {
                throw new ConfigurationException(
                    sprintf('Configuration file "%s" does not exist.', $explicitPath),
                );
            }

            return $explicitPath;
        }

        foreach (self::DEFAULT_FILE_NAMES as $name) {
            $candidate = $workingDirectory->path . '/' . $name;

            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new ConfigurationException(
            sprintf(
                'No configuration file found in %s (looked for crucible.php, crucible.dist.php). Run "crucible --init" to create one.',
                $workingDirectory->path,
            ),
        );
    }
}
