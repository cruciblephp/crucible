<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Architecture;

use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Impact\ReferenceScanner;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use function array_any;
use function array_keys;
use function class_exists;
use function enum_exists;
use function file_get_contents;
use function in_array;
use function interface_exists;
use function is_dir;
use function ksort;
use function preg_match;
use function preg_quote;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function trait_exists;

/**
 * The set of classes an architecture rule reasons about, and what each
 * one references (D-088).
 *
 * The universe is the **configured source**, not the whole project:
 * `->source(include: [...])` already states which directories hold the
 * code you own — coverage uses it, and an architecture rule is asking a
 * question about exactly that code. Vendor and tests are outside it by
 * construction, so no rule needs to remember to exclude them.
 *
 * Everything here is derived with machinery that already existed:
 * `ClassLocator` names the classes a file declares, `ReferenceScanner`
 * names the symbols it references. Both are memoized per run — a rule
 * suite asks the same questions of the same files repeatedly.
 */
final class ArchitectureUniverse
{
    /** @var ?array<string, string> class => absolute file */
    private ?array $classes = null;

    /** @var array<string, list<string>> */
    private array $references = [];

    public function __construct(
        private readonly Source $source,
        private readonly WorkingDirectory $workingDirectory,
        private readonly ClassLocator $locator = new ClassLocator(),
        private readonly ReferenceScanner $scanner = new ReferenceScanner(),
    ) {}

    /**
     * Does a class name fall under a target?
     *
     * The one rule both spellings resolve by — `arch()->expect()` and
     * the pest `expect()` arch matchers — so they cannot drift into
     * meaning different things by the same words. Without a wildcard a
     * target is the symbol itself OR anything beneath it, and the
     * boundary is a namespace separator: `App\Model` does not match
     * `App\Models\User`. ✓ The incumbent agrees, by a different route:
     * it maps a target to a DIRECTORY, so a name that is not one
     * resolves to nothing.
     *
     * `*` matches within a namespace segment, `**` across them. Pest has
     * no wildcard grammar at all — ✓ measured, a `*` becomes a literal
     * path segment and throws — so this is a superset, never a
     * divergence on anything Pest can express.
     */
    public static function matches(string $class, string $pattern): bool
    {
        if (!str_contains($pattern, '*')) {
            return $class === $pattern || str_starts_with($class, rtrim($pattern, '\\') . '\\');
        }

        $regex = preg_quote($pattern, '/');
        $regex = str_replace('\*\*', '.*', $regex);
        $regex = str_replace('\*', '[^\\\\]*', $regex);

        return preg_match('/^' . $regex . '$/', $class) === 1;
    }

    /**
     * Every class in the universe that falls under a target.
     *
     * @return list<class-string>
     */
    public function matching(string $target): array
    {
        $found = [];

        /** @var class-string $class */
        foreach (array_keys($this->classes()) as $class) {
            if (self::matches($class, $target)) {
                $found[] = $class;
            }
        }

        return $found;
    }

    /**
     * Whether the symbol can actually be brought into the process.
     *
     * The check has to catch `Error`, not only return false, and the
     * reason is measured rather than defensive: `src/Bridge/Laravel`
     * extends `Illuminate\Console\Command`, which is deliberately not a
     * dependency, so autoloading one of those classes raises "Class
     * Illuminate\Console\Command not found" from inside the class
     * declaration. An unguarded class_exists() turned 37 of Crucible's
     * own arch tests into errors.
     *
     * ✓ The incumbent ends its object factory the same way —
     * `try { new ReflectionClass($name); } catch (Error|ReflectionException) { return null; }`
     * — so a class whose parent is absent is dropped from its universe
     * too. Matching that is the point: a symbol nothing can load is one
     * no rule can reason about, and the alternative is a universe where
     * the text-only matchers have an opinion about a class the
     * reflection-backed ones cannot see.
     */
    private function loadable(string $class): bool
    {
        try {
            return class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Every class the configured source declares.
     *
     * @return array<string, string> class name => absolute file, sorted by name
     */
    public function classes(): array
    {
        if ($this->classes !== null) {
            return $this->classes;
        }

        $found = [];

        foreach ($this->files() as $file) {
            // declarationsIn(), not classesIn(): a rule about
            // interfaces, traits or enums cannot be written if they are
            // not in the universe it reasons over. toBeInterfaces()
            // shipped before this and could never pass.
            foreach ($this->locator->declarationsIn($file) as $class) {
                // A symbol the autoloader cannot resolve is not in the
                // universe. ✓ Measured against pest 5.1.1 (2026-09-06):
                // its object factory ends in `new ReflectionClass($name)`
                // and returns null when that throws, so a class declared
                // under a name its own path does not imply is dropped
                // silently — the namespace then resolves to ZERO objects
                // and every matcher passes vacuously over it.
                //
                // Crucible kept such a class and answered `false` for
                // every reflection-backed matcher, which is a different
                // verdict about the same source: 30 cells of the arch
                // grid. Dropping it is not deference to the incumbent —
                // a symbol nothing can load is one no rule can reason
                // about, and the text-only matchers would otherwise be
                // the only ones with an opinion about it.
                if (!$this->loadable($class)) {
                    continue;
                }

                $found[$class] = $file;
            }
        }

        ksort($found);

        return $this->classes = $found;
    }

    /**
     * The symbols one class's file references — memoized, because a
     * layering rule asks this of every file once per expectation.
     *
     * @return list<string>
     */
    public function referencesOf(string $class): array
    {
        $file = $this->classes()[$class] ?? null;

        if ($file === null) {
            return [];
        }

        if (isset($this->references[$file])) {
            return $this->references[$file];
        }

        $source = file_get_contents($file);

        return $this->references[$file] = $source === false ? [] : $this->scanner->referencesIn($source);
    }

    public function fileOf(string $class): ?string
    {
        return $this->classes()[$class] ?? null;
    }

    /**
     * @return list<string> absolute paths of the PHP files under the configured source
     */
    private function files(): array
    {
        $files = [];

        foreach ($this->source->includeFiles as $file) {
            $files[] = $this->absolute($file);
        }

        foreach ($this->source->includeDirectories as $directory) {
            $root = $this->absolute($directory);

            if (!is_dir($root)) {
                continue;
            }

            /** @var SplFileInfo $entry */
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $entry) {
                $path = $entry->getPathname();

                if ($entry->isFile() && str_ends_with($path, '.php') && !$this->excluded($path)) {
                    $files[] = $path;
                }
            }
        }

        return $files;
    }

    private function excluded(string $path): bool
    {
        if (in_array($path, $this->source->excludeFiles, true)) {
            return true;
        }

        foreach ($this->source->excludeFiles as $file) {
            if ($path === $this->absolute($file)) {
                return true;
            }
        }
        return array_any($this->source->excludeDirectories, fn($directory) => str_starts_with($path, rtrim($this->absolute($directory), '/') . '/'));
    }

    private function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : rtrim($this->workingDirectory->path, '/') . '/' . $path;
    }
}
