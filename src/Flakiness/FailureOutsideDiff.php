<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Flakiness;

use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Test\TestId;

use function array_any;
use function array_key_exists;
use function array_keys;
use function in_array;
use function str_ends_with;
use function str_starts_with;

/**
 * The DeFlaker check (Bell et al., ICSE 2018): a fresh failure whose
 * execution touched none of the changed code was most likely not
 * caused by the change — flaky or environmental, no reruns needed.
 * The per-test line map (D-047) is the execution record; the git diff
 * is the change set; this class is the pure intersection logic.
 *
 * The claim "executed no changed code" is only sound when every
 * changed PHP file is coverage-observable. Changed files inside the
 * coverage scope compare against the map; a changed test file
 * attributes to the tests it declares (a failing test whose own file
 * changed is never a suspect); any other changed PHP file — bootstrap,
 * helpers, out-of-scope support code — makes the whole check
 * unanswerable, and the answer is silence, not a guess.
 */
final readonly class FailureOutsideDiff
{
    public function __construct(
        private TestLineMap $map,
    ) {}

    /**
     * The failing tests that executed none of the changed files.
     *
     * @param list<non-empty-string> $failed     candidate failing test ids (quarantined and
     *                                           repeat failures already excluded by the caller)
     * @param list<non-empty-string> $changedPhp project-relative changed .php files
     * @param list<non-empty-string> $scope      project-relative coverage-scope prefixes
     *                                           (directories end in '/', files compare whole)
     *
     * @return list<non-empty-string> test ids, in the given failure order
     */
    public function suspects(array $failed, array $changedPhp, array $scope): array
    {
        if ($failed === [] || $changedPhp === [] || $scope === []) {
            return [];
        }

        /** @var array<non-empty-string, true> $declaring */
        $declaring = [];

        foreach (array_keys($this->map->tests) as $id) {
            $file = TestId::fromString($id)?->file;

            if ($file !== null) {
                $declaring[$file] = true;
            }
        }

        foreach ($failed as $id) {
            $file = TestId::fromString($id)?->file;

            if ($file !== null) {
                $declaring[$file] = true;
            }
        }

        /** @var array<non-empty-string, true> $inScope */
        $inScope = [];

        foreach ($changedPhp as $changed) {
            if ($this->inScope($changed, $scope)) {
                $inScope[$changed] = true;

                continue;
            }

            if (!isset($declaring[$changed])) {
                // Unobservable code changed — the check cannot claim
                // the failure ran none of it.
                return [];
            }
        }

        if ($inScope === []) {
            return [];
        }

        $suspects = [];

        foreach ($failed as $id) {
            $executed = $this->map->tests[$id] ?? [];

            if ($executed === []) {
                continue; // unknown to the last coverage run
            }

            $own = TestId::fromString($id)?->file;

            if ($own !== null && in_array($own, $changedPhp, true)) {
                continue; // its own code changed — the diff is a suspect
            }

            $touched = array_any(
                array_keys($executed),
                static fn(string $file): bool => array_key_exists($file, $inScope),
            );

            if (!$touched) {
                $suspects[] = $id;
            }
        }

        return $suspects;
    }

    /**
     * @param non-empty-string       $file
     * @param list<non-empty-string> $scope
     */
    private function inScope(string $file, array $scope): bool
    {
        // Directory prefixes end in '/'; anything else is a whole
        // file, and 'src/A.php' must not claim 'src/A.php.bak'.
        return array_any(
            $scope,
            static fn(string $prefix): bool => $file === $prefix
                || (str_ends_with($prefix, '/') && str_starts_with($file, $prefix)),
        );
    }
}
