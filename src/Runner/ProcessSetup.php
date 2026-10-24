<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Compat\MockeryCompatibility;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_key_exists;
use function array_map;
use function define;
use function defined;
use function get_include_path;
use function getenv;
use function implode;
use function ini_set;
use function is_file;
use function putenv;
use function set_include_path;
use function sprintf;
use function str_starts_with;

use const PATH_SEPARATOR;
use const PHP_EOL;

/**
 * What a process does before it discovers a suite: compatibility aliases, then bootstrap, ini, env and constants.
 */
final class ProcessSetup
{
    /**
     * What the environment held, before applyEnv() changed it, for each
     * name it changed: the value, or false when it was undefined.
     *
     * @var array<non-empty-string, string|false>
     */
    private static array $replaced = [];

    /**
     * The coexistence policy (DESIGN.md D-019): aliases load automatically
     * only when the real PHPUnit is absent; with PHPUnit installed they
     * require an explicit opt-in, which fails fast if PHPUnit classes are
     * already loaded. Runs before the bootstrap so the aliases win the
     * namespace. Returns the user-facing note instead of printing it -
     * worker processes must keep stdout for the protocol.
     */
    public static function compatibility(Configuration $configuration): ?string
    {
        // The Mockery-name aliases follow the same coexistence
        // principle independently: on exactly when the real package
        // is absent (D-060).
        if (MockeryCompatibility::shouldAutoEnable()) {
            MockeryCompatibility::load();
        }

        if ($configuration->phpunitCompatibility === true) {
            PhpUnitCompatibility::load();

            return null;
        }

        if ($configuration->phpunitCompatibility === false) {
            return null;
        }

        // Auto policy: drop-in when the real PHPUnit is absent.
        if (PhpUnitCompatibility::shouldAutoEnable()) {
            PhpUnitCompatibility::load();

            return null;
        }

        return 'Note: phpunit/phpunit is installed, so PHPUnit-namespace compatibility aliases are'
            . PHP_EOL
            . 'disabled. Tests must extend Crucible\'s TestCase, or opt in with ->phpunitCompatibility().';
    }

    /**
     * @param list<non-empty-string> $includePaths
     */
    public static function phpSettings(Configuration $configuration, WorkingDirectory $workingDirectory, Overrides $overrides = new Overrides(), array $includePaths = []): void
    {
        // Before the bootstrap: a suite that relies on include_path expects
        // it set by the time its own loader runs.
        if ($includePaths !== []) {
            $absolute = array_map(
                $workingDirectory->absolute(...),
                $includePaths,
            );

            set_include_path(implode(PATH_SEPARATOR, [...$absolute, get_include_path()]));
        }

        // Run-wide display state, set before any test can render a failure.
        Differ::context($overrides->diffContext);

        $bootstrap = $overrides->bootstrap ?? $configuration->bootstrap;

        if ($bootstrap !== null) {
            if (!str_starts_with($bootstrap, '/')) {
                $bootstrap = $workingDirectory->path . '/' . $bootstrap;
            }

            if (is_file($bootstrap)) {
                require_once $bootstrap;
            }
        }

        foreach ($configuration->php->ini as $name => $value) {
            ini_set($name, $value);
        }

        foreach ($configuration->php->env as $name => $value) {
            self::applyEnv($name, $value, isset($configuration->php->forcedEnv[$name]));
        }

        foreach ($configuration->php->constants as $name => $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
    }

    /**
     * A configured env value, as PHPUnit's <env> applies it (D-151): ✓
     * measured on 13.4.3 and 12.5.40, a variable the environment already
     * defines, empty included, is kept unless forced, and $_SERVER is
     * never written, forced or not.
     *
     * @param non-empty-string $name
     */
    private static function applyEnv(string $name, string $value, bool $force): void
    {
        $current = getenv($name);

        if ($force || $current === false) {
            if ($current !== $value && !array_key_exists($name, self::$replaced)) {
                self::$replaced[$name] = $current;
            }

            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;

            return;
        }

        $_ENV[$name] = $current;
    }

    /**
     * The environment as the run started, for a child that applies the
     * configuration itself: a worker inherits what the user exported, not
     * what this process configured, as a serial run's tests see it. Null
     * when nothing was changed, for plain inheritance.
     *
     * @return ?array<string, string>
     */
    public static function inheritedEnvironment(): ?array
    {
        if (self::$replaced === []) {
            return null;
        }

        $environment = getenv();

        foreach (self::$replaced as $name => $original) {
            if ($original === false) {
                unset($environment[$name]);
            } else {
                $environment[$name] = $original;
            }
        }

        return $environment;
    }

    /** A value Crucible itself hands the process, the parallel tokens: every channel. */
    public static function exportEnv(string $name, string $value): void
    {
        putenv(sprintf('%s=%s', $name, $value));
        $_ENV[$name]    = $value;
        $_SERVER[$name] = $value;
    }
}
