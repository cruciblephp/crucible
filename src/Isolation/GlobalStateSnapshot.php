<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Isolation;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use ReflectionProperty;

use function array_key_exists;
use function array_keys;
use function array_slice;
use function chdir;
use function count;
use function error_reporting;
use function getcwd;
use function getenv;
use function in_array;
use function ini_get;
use function ini_get_all;
use function ini_set;
use function is_string;
use function putenv;
use function sprintf;

/**
 * A point-in-time capture of PHP's mutable global surface: $GLOBALS,
 * eligible static properties, environment variables, ini settings,
 * error_reporting, and the working directory.
 *
 * restore() puts the world back (the VMVM model: reset state
 * in-process instead of paying for a process per test); changes()
 * names what a test polluted — the diagnostic behind
 * beStrictAboutChangesToGlobalState's risky outcome.
 *
 * Object values are held by reference (spec behavior): mutations
 * inside retained objects are neither restored nor detected.
 */
final readonly class GlobalStateSnapshot
{
    /** @var array<string, mixed> */
    private array $globals;

    /** @var array<int, mixed> indexed like $statics */
    private array $staticValues;

    /** @var list<array{class-string, non-empty-string}> */
    private array $statics;

    /** @var array<string, string> */
    private array $environment;

    /** @var array<string, string> */
    private array $ini;

    private int $errorReporting;

    private WorkingDirectory $workingDirectory;

    /**
     * @param list<non-empty-string> $excludedGlobals
     * @param list<array{class-string, non-empty-string}> $excludedStatics
     */
    public function __construct(
        StaticRegistry $registry,
        private array $excludedGlobals = [],
        private array $excludedStatics = [],
        private bool $includeStatics = true,
    ) {
        $globals = [];

        foreach ($GLOBALS as $name => $value) {
            if ($name === 'GLOBALS' || in_array($name, $this->excludedGlobals, true)) {
                continue;
            }

            $globals[(string) $name] = $value;
        }

        $this->globals = $globals;

        $statics      = [];
        $staticValues = [];

        if ($this->includeStatics) {
            foreach ($registry->eligibleProperties() as $pair) {
                if (in_array($pair, $this->excludedStatics, true)) {
                    continue;
                }

                $property = new ReflectionProperty($pair[0], $pair[1]);

                if (!$property->isInitialized()) {
                    continue;
                }

                $statics[]      = $pair;
                $staticValues[] = $property->getValue();
            }
        }

        $this->statics      = $statics;
        $this->staticValues = $staticValues;

        /** @var array<string, string> $environment */
        $environment       = getenv();
        $this->environment = $environment;

        $ini      = [];
        $iniTable = ini_get_all(null, false);

        foreach ($iniTable === false ? [] : $iniTable as $name => $value) {
            if (is_string($value)) {
                $ini[(string) $name] = $value;
            }
        }

        $this->ini              = $ini;
        $this->errorReporting   = error_reporting();
        $this->workingDirectory = WorkingDirectory::current();
    }

    public function restore(): void
    {
        // Globals: reinstate snapshot values, drop additions.
        foreach ($this->globals as $name => $value) {
            $GLOBALS[$name] = $value;
        }

        foreach (array_keys($GLOBALS) as $name) {
            if ($name === 'GLOBALS' || in_array($name, $this->excludedGlobals, true)) {
                continue;
            }

            if (!array_key_exists($name, $this->globals)) {
                unset($GLOBALS[$name]);
            }
        }

        foreach ($this->statics as $index => [$class, $property]) {
            (new ReflectionProperty($class, $property))->setValue(null, $this->staticValues[$index]);
        }

        // Environment: reinstate and drop additions.
        foreach ($this->environment as $name => $value) {
            if (getenv($name) !== $value) {
                putenv(sprintf('%s=%s', $name, $value));
            }
        }

        /** @var array<string, string> $currentEnvironment */
        $currentEnvironment = getenv();

        foreach (array_keys($currentEnvironment) as $name) {
            if (!array_key_exists($name, $this->environment)) {
                putenv($name);
            }
        }

        foreach ($this->ini as $name => $value) {
            if (ini_get($name) !== $value) {
                @ini_set($name, $value);
            }
        }

        error_reporting($this->errorReporting);

        // The non-empty half of this guard was the annotation's job and is
        // now the type's, so only the comparison survives.
        if (getcwd() !== $this->workingDirectory->path) {
            @chdir($this->workingDirectory->path);
        }
    }

    /**
     * Human-readable names of everything that differs from the
     * snapshot, capped for readable risky reasons.
     *
     * @return list<non-empty-string>
     */
    public function changes(int $limit = 5): array
    {
        $changes = [];

        foreach ($this->globals as $name => $value) {
            if (!array_key_exists($name, $GLOBALS) || $GLOBALS[$name] !== $value) {
                $changes[] = sprintf('global $%s', $name);
            }
        }

        foreach (array_keys($GLOBALS) as $name) {
            if ($name !== 'GLOBALS' && !array_key_exists($name, $this->globals) && !in_array($name, $this->excludedGlobals, true)) {
                $changes[] = sprintf('global $%s (added)', (string) $name);
            }
        }

        foreach ($this->statics as $index => [$class, $property]) {
            if ((new ReflectionProperty($class, $property))->getValue() !== $this->staticValues[$index]) {
                $changes[] = sprintf('%s::$%s', $class, $property);
            }
        }

        foreach ($this->environment as $name => $value) {
            if (getenv($name) !== $value) {
                $changes[] = sprintf('env %s', $name);
            }
        }

        if (error_reporting() !== $this->errorReporting) {
            $changes[] = 'error_reporting';
        }

        if (count($changes) > $limit) {
            $changes   = array_slice($changes, 0, $limit);
            $changes[] = '…';
        }

        return $changes;
    }
}
