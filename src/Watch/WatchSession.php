<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Watch;

use LucianoPereira\Crucible\Impact\ImpactRule;

use function array_any;
use function array_fill_keys;
use function array_keys;
use function count;
use function rtrim;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The watch state machine (growth G3, atoum's loop mode): while
 * anything is red, every change-triggered run carries the failed
 * files along — failed-only is *sticky* across edits (the Vitest gap,
 * issue #10247, fixed by design) — and the first run that turns the
 * failed set green is confirmed with one full-suite run before the
 * session is called green again.
 *
 * Pure: no IO, no clock. The loop asks, this decides.
 */
final class WatchSession
{
    /** @var array<non-empty-string, true> test files with failures, sticky */
    private array $failed = [];

    /**
     * @param list<non-empty-string> $jsDirectories absolute roots of the configured Vitest suites (D-081);
     *                                              a change under one is in the JS graph, re-run via `--related`
     *                                              (where D-080 hands it to `vitest related`), not a full run
     * @param list<ImpactRule>       $rules         declared impact rules (D-083); a file one of them names is
     *                                              re-run via `--related` too — the child's ImpactSelection
     *                                              turns it into the groups it puts in doubt
     * @param non-empty-string       $projectRoot   what rule patterns are relative to
     */
    public function __construct(
        private readonly array $jsDirectories = [],
        private readonly array $rules = [],
        private readonly string $projectRoot = '.',
    ) {}

    /**
     * What a file-system change should run.
     *
     * @param list<non-empty-string> $changedFiles
     */
    public function onChange(array $changedFiles, bool $somethingDeleted): WatchRun
    {
        if ($somethingDeleted) {
            return WatchRun::full('files were deleted — the graph cannot see their dependents');
        }

        foreach ($changedFiles as $file) {
            if (!str_ends_with($file, '.php') && !$this->inJsGraph($file) && !$this->ruled($file)) {
                return WatchRun::full('a file outside every dependency graph changed');
            }
        }

        if ($this->failed !== []) {
            return WatchRun::related(
                [...$changedFiles, ...array_keys($this->failed)],
                'the change, plus the sticky failed set',
            );
        }

        return WatchRun::related($changedFiles, 'affected by the change');
    }

    /**
     * The failed-only run (the `f` key); null when everything is green.
     */
    public function failedRun(): ?WatchRun
    {
        if ($this->failed === []) {
            return null;
        }

        return WatchRun::related(
            array_keys($this->failed),
            sprintf('the %d failed file(s)', count($this->failed)),
        );
    }

    /**
     * Absorbs a finished run and returns the follow-up it demands:
     * a partial run that turned a red session green earns one full
     * confirmation run; everything else waits for the next change.
     *
     * @param list<non-empty-string> $failedFiles files with failed or errored tests
     */
    public function onResult(WatchRun $ran, array $failedFiles): ?WatchRun
    {
        $wasRed = $this->failed !== [];

        $this->failed = array_fill_keys($failedFiles, true);

        if ($failedFiles !== [] || !$wasRed || $ran->isFull()) {
            return null;
        }

        return WatchRun::full('the failed set is green again — confirming with the full suite');
    }

    public function isRed(): bool
    {
        return $this->failed !== [];
    }

    /**
     * Whether a changed file lives under a configured Vitest suite —
     * Vitest's own module graph (`vitest related`) can then narrow it,
     * so it need not widen the run to everything the way a truly
     * off-graph file does.
     *
     * @param non-empty-string $file
     */
    private function inJsGraph(string $file): bool
    {
        return array_any(
            $this->jsDirectories,
            static fn(string $directory): bool => str_starts_with($file, $directory . '/'),
        );
    }

    /**
     * Whether a declared rule names this file — which makes it worth a
     * narrowed re-run rather than the full suite the "outside every
     * graph" branch would otherwise demand.
     */
    private function ruled(string $file): bool
    {
        $prefix   = rtrim($this->projectRoot, '/') . '/';
        $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;

        return array_any(
            $this->rules,
            static fn(ImpactRule $rule): bool => $rule->matches($relative),
        );
    }
}
