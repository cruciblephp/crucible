<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;
use LucianoPereira\Crucible\Exceptions\ConfigurationException;

use function explode;
use function fnmatch;
use function glob;
use function is_array;
use function is_file;
use function is_int;
use function is_iterable;
use function is_string;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

use const DIRECTORY_SEPARATOR;
use const FNM_PATHNAME;

/**
 * The suite-level side of the pest dialect: Pest.php scope
 * registrations and shared datasets, discovered by walking the
 * directories above each test file (outermost first, each loaded
 * once per process). This is where pest()->extend()->in() and
 * dataset() deposit what they declare.
 */
final class PestScopes
{
    /** @var list<ScopeRegistration> */
    private static array $registrations = [];

    /**
     * Dataset name → declarations; several directories may own the
     * same name (the spec's per-folder scoping).
     *
     * @var array<string, list<array{non-empty-string, array<array-key, mixed>|Closure}>>
     */
    private static array $datasets = [];

    /** @var array<string, true> */
    private static array $loaded = [];

    /**
     * Non-null while a Pest.php or Datasets file is being required.
     *
     * @var ?non-empty-string
     */
    private static ?string $configDir = null;

    /**
     * Loads Pest.php and Datasets/*.php from every directory between
     * the project root and the test file's directory, top-down —
     * outer configuration registers before inner and therefore
     * applies first.
     *
     * @param non-empty-string $root
     * @param non-empty-string $directory
     */
    public static function loadConfiguration(string $root, string $directory): void
    {
        foreach (self::chain($root, $directory) as $dir) {
            if (isset(self::$loaded[$dir])) {
                continue;
            }

            self::$loaded[$dir] = true;

            if (is_file($dir . '/Pest.php')) {
                self::requireConfiguration($dir . '/Pest.php', $dir);
            }

            $datasetFiles = glob($dir . '/Datasets/*.php');

            foreach ($datasetFiles === false ? [] : $datasetFiles as $datasetFile) {
                self::requireConfiguration($datasetFile, $dir);
            }
        }
    }

    public static function configuring(): bool
    {
        return self::$configDir !== null;
    }

    /** @var ?non-empty-string */
    private static ?string $printer = null;

    /**
     * @param non-empty-string $name
     */
    public static function selectPrinter(string $name): void
    {
        self::$printer = $name;
    }

    /** The suite-declared printer, when a Pest.php chose one (D-066). */
    public static function printerPreference(): ?string
    {
        return self::$printer;
    }

    /** What pest() returns: a fresh registration, config-scoped. */
    public static function pest(): ScopeRegistration
    {
        $dir = self::$configDir ?? throw new ConfigurationException(
            'pest() can only be called from a Pest.php configuration file.',
        );

        $registration          = new ScopeRegistration($dir, fromConfigFile: true);
        self::$registrations[] = $registration;

        return $registration;
    }

    /** The legacy uses() spelling inside Pest.php. */
    public static function uses(string ...$names): ScopeRegistration
    {
        return self::pest()->assign(...$names);
    }

    /**
     * @param non-empty-string                     $name
     * @param iterable<array-key, mixed>|Closure   $rows
     */
    public static function dataset(string $name, iterable|Closure $rows): void
    {
        $dir = self::$configDir ?? PestRegistry::currentFileDirectory() ?? throw new ConfigurationException(
            'dataset() can only be called from a Pest.php, Datasets file, or while a *.pest.php file is loading.',
        );

        self::$datasets[$name][] = [$dir, $rows instanceof Closure ? $rows : self::materialize($rows)];
    }

    /**
     * Per-folder scoping: among declarations whose directory contains
     * the test file, the nearest wins. Closure-valued datasets are
     * re-invoked per use, so generators replay.
     *
     * @param non-empty-string $name
     * @param non-empty-string $fileDirectory
     *
     * @return array<array-key, mixed>
     */
    public static function resolveDataset(string $name, string $fileDirectory): array
    {
        $best       = null;
        $bestLength = -1;

        foreach (self::$datasets[$name] ?? [] as [$baseDir, $rows]) {
            $contains = $fileDirectory === $baseDir
                || str_starts_with($fileDirectory . DIRECTORY_SEPARATOR, $baseDir . DIRECTORY_SEPARATOR);

            if ($contains && strlen($baseDir) > $bestLength) {
                $best       = $rows;
                $bestLength = strlen($baseDir);
            }
        }

        if ($best === null) {
            throw new ConfigurationException(sprintf(
                "Unknown dataset '%s' — declare it via dataset() in a Datasets/*.php file or Pest.php above the test file.",
                $name,
            ));
        }

        if ($best instanceof Closure) {
            $rows = $best();

            if (!is_iterable($rows)) {
                throw new ConfigurationException(sprintf("Dataset '%s': the closure must return an iterable.", $name));
            }

            return self::materialize($rows);
        }

        return $best;
    }

    /**
     * Registrations that apply to a test file, in declaration order
     * (outer configuration first). Bare registrations contribute
     * hooks only — the builder reads $globs to know.
     *
     * @param non-empty-string $file absolute path
     *
     * @return list<ScopeRegistration>
     */
    public static function matching(string $file): array
    {
        $result = [];

        foreach (self::$registrations as $registration) {
            if (!str_starts_with($file, $registration->baseDir . DIRECTORY_SEPARATOR)) {
                continue;
            }

            if ($registration->globs === null) {
                if ($registration->hasHooks()) {
                    $result[] = $registration;
                }

                continue;
            }

            // Globs are written with '/' whatever the OS, and
            // FNM_PATHNAME only treats '/' as a separator.
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($registration->baseDir) + 1));

            foreach ($registration->globs as $glob) {
                if (self::matches($glob, $relative)) {
                    $result[] = $registration;

                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Iterators may yield any key type; only array keys survive.
     *
     * A generator's key is preserved only when the *first* one is a
     * string — a deliberate, author-chosen case label — matching real
     * Pest's own DatasetsRepository::processDatasets() exactly
     * (`iterator_to_array($generator, preserveKeys: is_string($generator->key()))`).
     * Implicit int keys are NOT preserved: a real-world dataset
     * commonly composes many sub-generators via `yield from`
     * (confirmed against spatie/laravel-data's own
     * tests/Datasets/RulesDataset.php, ~35 of them), each restarting
     * its own 0-based numbering — preserving those would silently
     * drop the vast majority of rows to array-key collision (found
     * the hard way: 129 materialized rows where real Pest — and the
     * real project's own test suite — produces 413, because only the
     * last colliding sub-generator's row for each int key survived).
     *
     * @param iterable<mixed, mixed> $rows
     *
     * @return array<array-key, mixed>
     */
    public static function materialize(iterable $rows): array
    {
        if (is_array($rows)) {
            return $rows;
        }

        $materialized = [];
        $preserveKeys = null;

        foreach ($rows as $key => $row) {
            if (!is_int($key) && !is_string($key)) {
                throw new ConfigurationException('Dataset keys must be ints or strings.');
            }

            $preserveKeys ??= is_string($key);

            if ($preserveKeys) {
                $materialized[$key] = $row;
            } else {
                $materialized[] = $row;
            }
        }

        return $materialized;
    }

    /**
     * A plain name selects a directory subtree; anything with a
     * wildcard is an fnmatch pattern where * does not cross '/'.
     */
    private static function matches(string $glob, string $relative): bool
    {
        // ->in('.') is the real-world idiom for "this whole directory,
        // no further restriction" — confirmed against
        // spatie/laravel-data's own Pest.php
        // (uses(TestCase::class)->in('.')), boilerplate that
        // spatie/laravel-package-tools' starter template ships to
        // every Laravel package built from it. '.' has no glob
        // metacharacters, so without this it fell into the literal-
        // prefix branch below and matched nothing.
        if ($glob === '.') {
            return true;
        }

        if (!str_contains($glob, '*') && !str_contains($glob, '?') && !str_contains($glob, '[')) {
            return $relative === $glob || str_starts_with($relative, $glob . '/');
        }

        return fnmatch($glob, $relative, FNM_PATHNAME);
    }

    /**
     * The directories from $root down to $directory, both inclusive.
     *
     * @param non-empty-string $root
     * @param non-empty-string $directory
     *
     * @return list<non-empty-string>
     */
    private static function chain(string $root, string $directory): array
    {
        if ($directory === $root || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
            return [$directory];
        }

        $chain   = [$root];
        $current = $root;

        foreach (explode(DIRECTORY_SEPARATOR, trim(substr($directory, strlen($root)), DIRECTORY_SEPARATOR)) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            $chain[] = $current;
        }

        return $chain;
    }

    /**
     * @param non-empty-string $baseDir
     */
    private static function requireConfiguration(string $file, string $baseDir): void
    {
        self::$configDir = $baseDir;

        try {
            (static function (string $__file): void {
                require $__file;
            })($file);
        } finally {
            self::$configDir = null;
        }
    }
}
