<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Test\TestId;

use function array_any;
use function str_starts_with;

/**
 * Per-test coverage collection (D-041): one driver window around
 * each test execution, filtered to the configured source scope —
 * the spec's <source> element is the coverage scope, vendor and
 * test files never count — and folded into one CoverageData.
 */
final readonly class CoverageCollector
{
    private CoverageData $data;

    /**
     * @param list<non-empty-string> $scope absolute directory prefixes (trailing slash) and files that count
     */
    public function __construct(
        private CoverageDriver $driver,
        private array $scope,
    ) {
        $this->data = new CoverageData();
    }

    public function begin(): void
    {
        $this->driver->start();
    }

    /**
     * Stops the window and returns it scope-filtered — recording is
     * a separate step (D-063): what a test may CONTRIBUTE depends on
     * its outcome and its covers claim, both known only after the
     * attempt settles.
     */
    public function end(): CoverageWindow
    {
        $window = $this->driver->stop();
        $lines  = [];

        foreach ($window->lines as $file => $values) {
            if ($this->inScope($file)) {
                $lines[$file] = $values;
            }
        }

        return new CoverageWindow($lines, $this->scoped($window->branches), $this->scoped($window->paths));
    }

    /**
     * Branch and path entries are keyed by file exactly like lines, so
     * the scope filter is the same filter.
     *
     * @param array<string, array<string, array{line: int, hit: int}>> $entries
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    private function scoped(array $entries): array
    {
        $kept = [];

        foreach ($entries as $file => $values) {
            if ($this->inScope($file)) {
                $kept[$file] = $values;
            }
        }

        return $kept;
    }

    /**
     * @param CoverageWindow $observed  what actually executed — feeds the per-test map (impact/mutation truth)
     * @param CoverageWindow $aggregate what the report may count — covers-filtered, hit-demoted when risky
     */
    public function record(TestId $test, CoverageWindow $observed, CoverageWindow $aggregate): void
    {
        $this->data->record($test->toString(), $observed, $aggregate);
    }

    public function data(): CoverageData
    {
        return $this->data;
    }

    /**
     * @return non-empty-string
     */
    public function driverName(): string
    {
        return $this->driver->name();
    }

    private function inScope(string $file): bool
    {
        // The driver reports the OS's own separator; a scope joined
        // with '/' on Windows would otherwise never match.
        $file = WorkingDirectory::native($file);

        return array_any($this->scope, static function (string $prefix) use ($file): bool {
            $prefix = WorkingDirectory::native($prefix);

            return $file === $prefix || str_starts_with($file, $prefix);
        });
    }
}
