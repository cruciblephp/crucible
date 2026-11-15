<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_key_first;
use function array_keys;
use function basename;
use function explode;
use function file_get_contents;
use function implode;
use function is_dir;
use function is_numeric;
use function is_string;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;
use function trim;

/**
 * The run's coverage targets, resolved from configuration and the two
 * command-line lists:
 *
 *     ->coverageTarget('Auth', 'modules/Auth', min: 100)
 *     --coverage-target Auth=modules/Auth --coverage-min Auth:100
 *
 * A target names a directory or a file, so judging it is narrowing the
 * collected data to it and running the ordinary floor rules on that
 * slice. Floor precedence for one target is: the floor on the target
 * itself, then `--coverage-min`, then `--min`.
 */
final readonly class CoverageTargetSet
{
    /** @param list<CoverageTarget> $targets */
    private function __construct(private array $targets) {}

    /**
     * A refusal is a string: nothing has run yet, so it is a message and
     * a non-zero exit, never a verdict about the code.
     *
     * @param list<CoverageTarget>   $configured
     * @param list<non-empty-string> $cliTargets
     * @param list<non-empty-string> $cliFloors
     */
    public static function resolve(array $configured, array $cliTargets, array $cliFloors, WorkingDirectory $workingDirectory): self|string
    {
        /** @var list<CoverageTarget> $targets */
        $targets = [];

        /** @var array<string, non-empty-string> $known */
        $known = [];

        foreach ($configured as $target) {
            if ($target->name !== null) {
                $key = strtolower($target->name);

                if (isset($known[$key])) {
                    return sprintf('Coverage target "%s" is named twice.', $target->name);
                }

                $known[$key] = $target->name;
            }

            $targets[] = new CoverageTarget(
                $target->name,
                self::absolute($target->path, $workingDirectory),
                $target->minimum,
            );
        }

        foreach ($cliTargets as $spec) {
            $parsed = self::parseTarget($spec);

            if (is_string($parsed)) {
                return $parsed;
            }

            [$name, $path] = $parsed;

            if ($name !== null) {
                $key = strtolower($name);

                if (isset($known[$key])) {
                    return sprintf('Coverage target "%s" is named twice.', $name);
                }

                $known[$key] = $name;
            }

            $targets[] = new CoverageTarget($name, self::absolute($path, $workingDirectory));
        }

        $floors     = [];
        $floorNames = [];

        foreach ($cliFloors as $spec) {
            $read = self::readFloors($spec, $workingDirectory);

            if (is_string($read)) {
                return $read;
            }

            foreach ($read as $name => $minimum) {
                $key = strtolower($name);

                if (isset($floors[$key])) {
                    return sprintf('A coverage floor for target "%s" is given twice.', $name);
                }

                $floors[$key]     = $minimum;
                $floorNames[$key] = $name;
            }
        }

        if ($floors !== []) {
            if ($known === []) {
                $key = array_key_first($floors);

                return sprintf(
                    'Coverage floor names unknown target "%s", and no coverage target is named. Name one with --coverage-target Name=path.',
                    $floorNames[$key],
                );
            }

            foreach (array_keys($floors) as $key) {
                if (!isset($known[$key])) {
                    return sprintf(
                        'Coverage floor names unknown target "%s". Known targets: %s.',
                        $floorNames[$key],
                        implode(', ', $known),
                    );
                }
            }
        }

        $resolved = [];

        foreach ($targets as $target) {
            if ($target->name === null) {
                $resolved[] = $target;

                continue;
            }

            $key   = strtolower($target->name);
            $floor = $target->minimum ?? ($floors[$key] ?? null);

            // The target's own floor wins over the option, the option
            // over --min; the last is applied per target at judging time.
            $resolved[] = new CoverageTarget($known[$key], $target->path, $floor);
        }

        return new self($resolved);
    }

    /** @return list<CoverageTarget> */
    public function targets(): array
    {
        return $this->targets;
    }

    public function isEmpty(): bool
    {
        return $this->targets === [];
    }

    /**
     * Every target's path, for the one narrowed report this run writes.
     *
     * @return list<non-empty-string>
     */
    public function paths(): array
    {
        $paths = [];

        foreach ($this->targets as $target) {
            $paths[] = $target->path;
        }

        return $paths;
    }

    /**
     * The pooled total is judged only when the run has no named target:
     * with names, each target answers for its own slice, so a passing
     * aggregate cannot hide a target under its floor.
     */
    public function judgesPooledTotal(): bool
    {
        if ($this->targets === []) {
            return true;
        }

        foreach ($this->targets as $target) {
            if ($target->name === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param non-empty-string $spec
     *
     * @return array{?non-empty-string, non-empty-string}|string
     */
    private static function parseTarget(string $spec): array|string
    {
        if (!str_contains($spec, '=')) {
            return [null, $spec];
        }

        [$name, $path] = explode('=', $spec, 2);
        $name          = trim($name);
        $path          = trim($path);

        if ($name === '' || !self::isName($name)) {
            return sprintf('Coverage target "%s" needs a name before "=" that is not a path.', $spec);
        }

        if ($path === '') {
            return sprintf('Coverage target "%s" needs a path after "=".', $name);
        }

        return [$name, $path];
    }

    /**
     * `Name:100`, `Auth,Activity:100`, or `@path` for a file with one
     * floor per line. Blank lines and lines starting with `#` are
     * ignored, so a floors file is reviewable in a diff.
     *
     * @return array<string, float>|string
     */
    private static function readFloors(string $spec, WorkingDirectory $workingDirectory): array|string
    {
        if (!str_starts_with($spec, '@')) {
            $colon = strrpos($spec, ':');
            $names = $colon === false ? '' : trim(substr($spec, 0, $colon));

            if ($names !== '' && !self::isName($names)) {
                return sprintf('Coverage floor "%s" goes on a target name, never a path.', $spec);
            }

            return self::parseFloor($spec) ?? sprintf('Coverage floor "%s" expects Name:percentage.', $spec);
        }

        $file = substr($spec, 1);
        $path = $workingDirectory->absolute($file);

        if ($file === '' || !is_string($contents = @file_get_contents($path))) {
            return sprintf('Coverage floors file "%s" cannot be read.', $file);
        }

        $floors = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parsed = self::parseFloor($line);

            if ($parsed === null) {
                return sprintf('Coverage floors file "%s" has an invalid line: "%s".', $file, $line);
            }

            foreach ($parsed as $name => $minimum) {
                $key = strtolower($name);

                if (isset($floors[$key])) {
                    return sprintf('Coverage floors file "%s" names target "%s" twice.', $file, $name);
                }

                $floors[$key] = $minimum;
            }
        }

        return $floors;
    }

    /**
     * One line's floors, or null when it is not Name:percentage. The
     * name list is split on commas, so `Auth,Activity:100` gives both
     * targets the same floor.
     *
     * @return ?array<string, float>
     */
    private static function parseFloor(string $spec): ?array
    {
        $colon = strrpos($spec, ':');

        if ($colon === false) {
            return null;
        }

        $names = trim(substr($spec, 0, $colon));
        $value = trim(substr($spec, $colon + 1));

        if ($names === '' || $value === '' || !is_numeric($value)) {
            return null;
        }

        $minimum = (float) $value;

        if ($minimum < 0.0 || $minimum > 100.0) {
            return null;
        }

        $floors = [];

        foreach (explode(',', $names) as $name) {
            $name = trim($name);

            if (!self::isName($name)) {
                return null;
            }

            $floors[$name] = $minimum;
        }

        return $floors;
    }

    /**
     * A floor goes on a name, never a path: a path silently taking a
     * floor for a target nobody can name is how a gate applies to
     * nothing and still reports success.
     */
    private static function isName(string $name): bool
    {
        return $name !== ''
            && !str_contains($name, '/')
            && !str_contains($name, '\\')
            && !str_contains($name, ':')
            && !str_ends_with($name, '.php');
    }

    /**
     * @return non-empty-string
     */
    private static function absolute(string $path, WorkingDirectory $workingDirectory): string
    {
        $absolute = $workingDirectory->absolute($path);
        $real     = realpath($absolute);

        if ($real !== false) {
            return is_dir($real) ? rtrim($real, '/\\') . '/' : $real;
        }

        // A target that does not exist still has to match on a path
        // boundary: a trailing extension names a file, anything else a
        // directory, so `modules/Auth` never takes `modules/AuthExtra`.
        return str_contains(basename($absolute), '.') ? $absolute : rtrim($absolute, '/\\') . '/';
    }
}
