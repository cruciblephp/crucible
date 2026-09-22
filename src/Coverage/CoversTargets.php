<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Attributes\CoversFunction;
use LucianoPereira\Crucible\Attributes\CoversMethod;
use LucianoPereira\Crucible\Attributes\CoversNothing;
use LucianoPereira\Crucible\Attributes\CoversTrait;
use LucianoPereira\Crucible\Attributes\UsesClass;
use LucianoPereira\Crucible\Attributes\UsesFunction;
use LucianoPereira\Crucible\Attributes\UsesMethod;
use LucianoPereira\Crucible\Attributes\UsesTrait;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use Throwable;

use function array_keys;
use function class_exists;
use function function_exists;
use function get_declared_classes;
use function get_declared_traits;
use function get_defined_functions;
use function interface_exists;
use function is_int;
use function method_exists;
use function sort;
use function trait_exists;

/**
 * A test's coverage-target claim, resolved from its Covers and Uses
 * metadata to file line ranges (D-063, oracle-pinned semantics):
 * Covers* ranges are what the test may CONTRIBUTE to the aggregate;
 * Uses* ranges extend only what it may EXECUTE under
 * beStrictAboutCoverageMetadata; CoversNothing declares a target,
 * contributes nothing, and is exempt from the strict check. A target
 * naming an undeclared symbol resolves to no range — never an engine
 * error; strict runs surface the resulting strays instead.
 */
final readonly class CoversTargets
{
    /**
     * @param list<array{string, int, int}> $covered file, first line, last line
     * @param list<array{string, int, int}> $allowed covered + the Uses* ranges
     */
    private function __construct(
        public bool $declared,
        public bool $coversNothing,
        private array $covered,
        private array $allowed,
    ) {}

    public static function from(MetadataCollection $metadata): self
    {
        $covered = [];
        $allowed = [];

        foreach ($metadata->ofType(CoversClass::class) as $covers) {
            self::addType($covered, $covers->className);
        }

        foreach ($metadata->ofType(CoversTrait::class) as $covers) {
            self::addType($covered, $covers->traitName);
        }

        foreach ($metadata->ofType(CoversMethod::class) as $covers) {
            self::addMethod($covered, $covers->className, $covers->methodName);
        }

        foreach ($metadata->ofType(CoversFunction::class) as $covers) {
            self::addFunction($covered, $covers->functionName);
        }

        $allowed = $covered;

        foreach ($metadata->ofType(UsesClass::class) as $uses) {
            self::addType($allowed, $uses->className);
        }

        foreach ($metadata->ofType(UsesTrait::class) as $uses) {
            self::addType($allowed, $uses->traitName);
        }

        foreach ($metadata->ofType(UsesMethod::class) as $uses) {
            self::addMethod($allowed, $uses->className, $uses->methodName);
        }

        foreach ($metadata->ofType(UsesFunction::class) as $uses) {
            self::addFunction($allowed, $uses->functionName);
        }

        $coversNothing = $metadata->has(CoversNothing::class);

        $declared = $coversNothing
            || $metadata->has(CoversClass::class) || $metadata->has(CoversTrait::class)
            || $metadata->has(CoversMethod::class) || $metadata->has(CoversFunction::class)
            || $metadata->has(UsesClass::class) || $metadata->has(UsesTrait::class)
            || $metadata->has(UsesMethod::class) || $metadata->has(UsesFunction::class);

        return new self($declared, $coversNothing, $covered, $allowed);
    }

    /**
     * The window this test may add to the aggregate report: identity
     * without a covers claim; otherwise executed lines OUTSIDE the
     * covered ranges (all of them, under CoversNothing) are DEMOTED
     * to executable-missed, never dropped — the oracle keeps stray
     * lines in the denominators (probe-pinned, D-063). The per-test
     * map is NOT this — the impact and mutation artifacts keep
     * observed truth.
     */
    public function contribution(CoverageWindow $window): CoverageWindow
    {
        if (!$this->declared) {
            return $window;
        }

        $lines = [];

        foreach ($window->lines as $file => $values) {
            foreach ($values as $line => $value) {
                $lines[$file][$line] = $value > 0 && ($this->coversNothing || !$this->inRanges($this->covered, $file, $line))
                    ? -1
                    : $value;
            }
        }

        return new CoverageWindow($lines, $this->claimed($window->branches), $this->claimed($window->paths));
    }

    /**
     * A hit outside what this test claims to cover contributes nothing
     * to the aggregate — the same rule the lines follow, applied to
     * branch and path entries alike.
     *
     * @param array<string, array<string, array{line: int, hit: int}>> $entries
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    private function claimed(array $entries): array
    {
        $kept = [];

        foreach ($entries as $file => $byId) {
            foreach ($byId as $id => $entry) {
                $kept[$file][$id] = $entry['hit'] > 0 && ($this->coversNothing || !$this->inRanges($this->covered, $file, $entry['line']))
                    ? ['line' => $entry['line'], 'hit' => 0]
                    : $entry;
            }
        }

        return $kept;
    }

    /**
     * The beStrictAboutCoverageMetadata check (D-063): executed lines
     * outside every covered/used range, named by their declaring unit
     * — the oracle reports class names, not files. Lines outside any
     * known unit (file-level code) cannot be named and are not strays.
     *
     * @return list<non-empty-string>
     */
    public function strays(CoverageWindow $window): array
    {
        if (!$this->declared || $this->coversNothing) {
            return [];
        }

        /** @var array<non-empty-string, true> $strays */
        $strays = [];
        $index  = null;

        foreach ($window->lines as $file => $values) {
            foreach ($values as $line => $value) {
                if ($value <= 0 || $this->inRanges($this->allowed, $file, $line)) {
                    continue;
                }

                // Built once per check, and only when a stray exists.
                $index ??= $this->unitIndex();

                foreach ($index[$file] ?? [] as $unit => [$start, $end]) {
                    if ($unit !== '' && $line >= $start && $line <= $end) {
                        $strays[$unit] = true;

                        break;
                    }
                }
            }
        }

        $names = array_keys($strays);

        sort($names);

        return $names;
    }

    /**
     * Whether the named type resolves — attempting the load, but never
     * letting the attempt end the run.
     *
     * class_exists() autoloads, and autoloading a class whose parent is
     * absent throws out of the loader. Crucible's own laravel bridge
     * does exactly that wherever illuminate is not installed, and one
     * #[CoversClass] naming it killed every coverage run over this
     * suite before the first report was written. Measured against the
     * oracle (phpunit 13.3.1, xdebug, a covered class extending a
     * missing parent): the run finishes at exit 0 and the target simply
     * resolves to nothing. So an unloadable name lands where an
     * undeclared one already does — no range, no engine error.
     *
     * @phpstan-assert-if-true class-string $name
     */
    private static function resolves(string $name): bool
    {
        try {
            return class_exists($name) || trait_exists($name) || interface_exists($name);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param list<array{string, int, int}> $target
     */
    private static function addType(array &$target, string $name): void
    {
        if (!self::resolves($name)) {
            return;
        }

        $reflection = new ReflectionClass($name);
        $file       = $reflection->getFileName();
        $start      = $reflection->getStartLine();
        $end        = $reflection->getEndLine();

        if ($file !== false && is_int($start) && is_int($end)) {
            $target[] = [$file, $start, $end];
        }
    }

    /**
     * @param list<array{string, int, int}> $target
     */
    private static function addMethod(array &$target, string $class, string $method): void
    {
        if (!self::resolves($class) || !method_exists($class, $method)) {
            return;
        }

        $reflection = new ReflectionMethod($class, $method);
        $file       = $reflection->getFileName();
        $start      = $reflection->getStartLine();
        $end        = $reflection->getEndLine();

        if ($file !== false && is_int($start) && is_int($end)) {
            $target[] = [$file, $start, $end];
        }
    }

    /**
     * @param list<array{string, int, int}> $target
     */
    private static function addFunction(array &$target, string $function): void
    {
        if (!function_exists($function)) {
            return;
        }

        $reflection = new ReflectionFunction($function);
        $file       = $reflection->getFileName();
        $start      = $reflection->getStartLine();
        $end        = $reflection->getEndLine();

        if ($file !== false && is_int($start) && is_int($end)) {
            $target[] = [$file, $start, $end];
        }
    }

    /**
     * @param list<array{string, int, int}> $ranges
     */
    private function inRanges(array $ranges, string $file, int $line): bool
    {
        foreach ($ranges as [$rangeFile, $start, $end]) {
            if ($rangeFile === $file && $line >= $start && $line <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * A per-file index of every declared class, trait, and user
     * function — the stray lines' declaring units. Built only when
     * the strict check actually finds a stray.
     *
     * @return array<string, array<string, array{int, int}>> file => unit name => range
     */
    private function unitIndex(): array
    {
        $index = [];

        foreach ([...get_declared_classes(), ...get_declared_traits()] as $type) {
            $reflection = new ReflectionClass($type);
            $file       = $reflection->getFileName();
            $start      = $reflection->getStartLine();
            $end        = $reflection->getEndLine();

            if ($file !== false && is_int($start) && is_int($end)) {
                $index[$file][$type] = [$start, $end];
            }
        }

        foreach (get_defined_functions()['user'] as $function) {
            $reflection = new ReflectionFunction($function);
            $file       = $reflection->getFileName();
            $start      = $reflection->getStartLine();
            $end        = $reflection->getEndLine();

            if ($file !== false && is_int($start) && is_int($end)) {
                $index[$file][$function] = [$start, $end];
            }
        }

        return $index;
    }
}
