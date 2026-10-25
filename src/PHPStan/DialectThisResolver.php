<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\PHPStan;

use LucianoPereira\Crucible\Dialect\Pest\PestTestCase;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use PHPStan\Analyser\DependencyTracker;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

use function array_map;
use function array_reverse;
use function class_exists;
use function dirname;
use function file_get_contents;
use function fnmatch;
use function is_file;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trait_exists;

use const DIRECTORY_SEPARATOR;
use const FNM_PATHNAME;

/**
 * What `$this` is inside a dialect file's bound closures (D-050,
 * completed in D-067): the file's own `uses(...)` wins; without one,
 * the ancestor Pest.php files' scoped `pest()->extend()->in()`
 * registrations decide — outer configuration first, the last
 * matching class wins, glob semantics mirroring PestScopes (plain
 * names are subtree prefixes, wildcards go through fnmatch, both
 * relative to the declaring directory). The default stays
 * PestTestCase. Trait arguments still cannot become part of a PHP
 * type — the documented limit, erring toward the base type, never a
 * wrong one.
 */
final class DialectThisResolver
{
    private const int WALK_CAP = 12;

    /** @var array<string, Type> */
    private array $byFile = [];

    /** @var array<string, list<array{names: list<non-empty-string>, globs: non-empty-list<string>}>> */
    private array $configurations = [];

    public function forFile(string $file): Type
    {
        return $this->byFile[$file] ??= $this->resolve($file);
    }

    /**
     * The type for the scope's file, with every Pest.php that could decide
     * it tracked as a dependency of that file, present or not: PHPStan's
     * result cache re-analyses the file when one is created, changed or
     * deleted (D-157).
     *
     * @param Scope&DependencyTracker $scope
     */
    public function forScope(Scope $scope): Type
    {
        $file = $scope->getFile();

        foreach (self::configurationDirectories(dirname($file)) as $directory) {
            $scope->trackFileDependency($directory . DIRECTORY_SEPARATOR . 'Pest.php');
        }

        return $this->forFile($file);
    }

    private function resolve(string $file): Type
    {
        $source = @file_get_contents($file);

        if ($source !== false) {
            foreach (UsesResolver::classRefs($source) as $name) {
                // The project's autoloader is loaded in the analysis
                // process, so existence answers are real. Traits
                // compose at runtime but are not types — skipped.
                if (class_exists($name) && !trait_exists($name)) {
                    return new ObjectType($name);
                }
            }
        }

        $scoped = $this->scopedClass($file);

        return new ObjectType($scoped ?? PestTestCase::class);
    }

    /**
     * The class the ancestor Pest.php configurations assign to this
     * file — the analysis-time PestBuilder::effectiveState walk.
     *
     * @return ?class-string
     */
    private function scopedClass(string $file): ?string
    {
        // Ancestors from the file upward, then applied outer-first —
        // the runtime loads configurations root-down, and the last
        // matching class wins ($class = $scope->class ?? $class).
        $class = null;

        foreach (array_reverse(self::configurationDirectories(dirname($file))) as $base) {
            foreach ($this->registrationsIn($base) as $registration) {
                if (!str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
                    continue;
                }

                // Globs are written with '/' whatever the OS, and
                // FNM_PATHNAME only treats '/' as a separator. An absolute
                // one, `->in(__DIR__)`, is matched against the absolute file.
                $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($base) + 1));
                $absolute = str_replace(DIRECTORY_SEPARATOR, '/', $file);
                $matched  = false;

                foreach ($registration['globs'] as $glob) {
                    $glob = str_replace(DIRECTORY_SEPARATOR, '/', $glob);

                    if ($this->matches($glob, WorkingDirectory::isAbsolute($glob) ? $absolute : $relative)) {
                        $matched = true;

                        break;
                    }
                }

                if (!$matched) {
                    continue;
                }

                foreach ($registration['names'] as $name) {
                    if (class_exists($name) && !trait_exists($name)) {
                        $class = $name;

                        break;
                    }
                }
            }
        }

        return $class;
    }

    /**
     * The directories whose Pest.php configures what is in $directory:
     * itself and its ancestors up to the project root, innermost first.
     *
     * @return non-empty-list<string>
     */
    public static function configurationDirectories(string $directory): array
    {
        $directories = [$directory];

        for ($depth = 1; $depth < self::WALK_CAP; $depth++) {
            $isRoot = is_file($directory . DIRECTORY_SEPARATOR . 'crucible.php')
                || is_file($directory . DIRECTORY_SEPARATOR . 'crucible.dist.php')
                || is_file($directory . DIRECTORY_SEPARATOR . 'composer.json');

            $parent = dirname($directory);

            if ($isRoot || $parent === $directory) {
                break;
            }

            $directory     = $parent;
            $directories[] = $directory;
        }

        return $directories;
    }

    /**
     * @return list<array{names: list<non-empty-string>, globs: non-empty-list<string>}>
     */
    private function registrationsIn(string $directory): array
    {
        if (isset($this->configurations[$directory])) {
            return $this->configurations[$directory];
        }

        $config = $directory . DIRECTORY_SEPARATOR . 'Pest.php';
        $source = is_file($config) ? @file_get_contents($config) : false;

        if ($source === false) {
            return $this->configurations[$directory] = [];
        }

        // `__DIR__` is the directory this Pest.php sits in (D-157).
        $registrations = [];

        foreach (UsesResolver::scopedRegistrations($source) as $registration) {
            $registration['globs'] = array_map(
                static fn(string $glob): string => str_replace(UsesResolver::DIR, $directory, $glob),
                $registration['globs'],
            );
            $registrations[] = $registration;
        }

        return $this->configurations[$directory] = $registrations;
    }

    /** The PestScopes glob rule, mirrored. */
    private function matches(string $glob, string $relative): bool
    {
        if (!str_contains($glob, '*') && !str_contains($glob, '?') && !str_contains($glob, '[')) {
            return $relative === $glob || str_starts_with($relative, $glob . '/');
        }

        return fnmatch($glob, $relative, FNM_PATHNAME);
    }
}
