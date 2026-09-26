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
use Throwable;

use function array_any;
use function get_debug_type;
use function is_file;
use function is_int;
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

        // Loading is a require, so whatever the file throws — an exception,
        // a ParseError from a typo — would escape as a fatal with a stack
        // trace. It is a configuration that cannot load, and says so.
        try {
            $result = require $path;
        } catch (Throwable $throwable) {
            // The cause's code, as every rethrow in Crucible hands it on —
            // unless it is not an int: this catches any Throwable, and a
            // PDOException's code is a string ('HY000') that would throw a
            // TypeError from this handler instead of the message below.
            $code = $throwable->getCode();

            throw new ConfigurationException(sprintf(
                '%s could not be loaded: %s: %s (line %d)',
                $path,
                $throwable::class,
                $throwable->getMessage(),
                $throwable->getLine(),
            ), is_int($code) ? $code : 0, $throwable);
        }

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
