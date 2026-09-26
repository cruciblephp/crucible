<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * Aggregate counts of a finished run, keyed by outcome.
 */
final readonly class RunSummary
{
    /**
     * @param int $untested how many of the skipped went untested because
     *                      they could not run — an unmet #[Requires*] or
     *                      an unmet dependency (D-075). A subset of
     *                      $skipped, so it is deliberately absent from
     *                      total() and toArray(): the outcome counts
     *                      already include it, and adding it would
     *                      double-count.
     */
    public function __construct(
        public int $passed = 0,
        public int $failed = 0,
        public int $errored = 0,
        public int $skipped = 0,
        public int $incomplete = 0,
        public int $risky = 0,
        public int $deprecations = 0,
        public int $notices = 0,
        public int $warnings = 0,
        public int $untested = 0,
    ) {}

    /** One test's outcome, as a tally. */
    public static function of(Outcome $outcome): self
    {
        return match ($outcome) {
            Outcome::Passed     => new self(passed: 1),
            Outcome::Failed     => new self(failed: 1),
            Outcome::Errored    => new self(errored: 1),
            Outcome::Skipped    => new self(skipped: 1),
            Outcome::Incomplete => new self(incomplete: 1),
            Outcome::Risky      => new self(risky: 1),
        };
    }

    public function total(): int
    {
        return $this->passed + $this->failed + $this->errored
             + $this->skipped + $this->incomplete + $this->risky;
    }

    /**
     * Field-wise sum — how external test results (Vitest, D-079) fold
     * into the run's tally. The run-completeness flag is computed on the
     * PHP plan before this merge, so a folded-in suite never touches it.
     */
    public function plus(self $other): self
    {
        return new self(
            passed: $this->passed + $other->passed,
            failed: $this->failed + $other->failed,
            errored: $this->errored + $other->errored,
            skipped: $this->skipped + $other->skipped,
            incomplete: $this->incomplete + $other->incomplete,
            risky: $this->risky + $other->risky,
            deprecations: $this->deprecations + $other->deprecations,
            notices: $this->notices + $other->notices,
            warnings: $this->warnings + $other->warnings,
            untested: $this->untested + $other->untested,
        );
    }

    public function successful(): bool
    {
        return $this->failed === 0 && $this->errored === 0;
    }

    public function hasIssues(): bool
    {
        return $this->deprecations > 0 || $this->notices > 0 || $this->warnings > 0;
    }

    /**
     * The issue tallies, separate from the outcome counts: outcomes
     * partition the tests, issues do not (a passing test can carry
     * three deprecations), so mixing them in one map would break
     * every consumer that sums outcomes.
     *
     * @return array<string, int>
     */
    public function issueCounts(): array
    {
        return [
            'deprecations' => $this->deprecations,
            'notices'      => $this->notices,
            'warnings'     => $this->warnings,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            Outcome::Passed->value     => $this->passed,
            Outcome::Failed->value     => $this->failed,
            Outcome::Errored->value    => $this->errored,
            Outcome::Skipped->value    => $this->skipped,
            Outcome::Incomplete->value => $this->incomplete,
            Outcome::Risky->value      => $this->risky,
        ];
    }
}
