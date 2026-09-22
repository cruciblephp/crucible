<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

/**
 * One collection window's normalized yield: the line map every
 * driver produces, plus the branch map only xdebug's branch analysis
 * can (D-062) — pcov windows carry an empty branch map, honestly.
 */
final readonly class CoverageWindow
{
    /**
     * @param array<string, array<int, int>>                          $lines    file => line => value (>0 executed, -1 missed, -2 dead)
     * @param array<string, array<string, array{line: int, hit: int}>> $branches file => branch id => first line + hit flag
     * @param array<string, array<string, array{line: int, hit: int}>> $paths    file => path id => the same shape (--path-coverage)
     */
    public function __construct(
        public array $lines = [],
        public array $branches = [],
        public array $paths = [],
    ) {}

    /**
     * The same window with every hit demoted to executable-missed —
     * a risky test's aggregate contribution (D-063, probe-pinned:
     * the oracle discards the coverage but keeps the denominators).
     */
    public function withoutHits(): self
    {
        $lines = [];

        foreach ($this->lines as $file => $values) {
            foreach ($values as $line => $value) {
                $lines[$file][$line] = $value > 0 ? -1 : $value;
            }
        }

        return new self($lines, $this->unhit($this->branches), $this->unhit($this->paths));
    }

    /**
     * @param array<string, array<string, array{line: int, hit: int}>> $entries
     *
     * @return array<string, array<string, array{line: int, hit: int}>>
     */
    private function unhit(array $entries): array
    {
        $cleared = [];

        foreach ($entries as $file => $byId) {
            foreach ($byId as $id => $entry) {
                $cleared[$file][$id] = ['line' => $entry['line'], 'hit' => 0];
            }
        }

        return $cleared;
    }
}
