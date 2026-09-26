<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use function array_filter;
use function count;

/**
 * The outcome of a mutation run: every verdict, plus the mutation score
 * indicator (MSI) over the mutants a test actually covered. A killed,
 * timed-out, or errored mutant was *detected* — the suite reacted to the
 * change; an escaped one is the gap the suite cannot see; a not-covered
 * mutant is excluded from the score entirely (no test ran it, so it
 * measures coverage, not test strength).
 */
final readonly class MutationReport
{
    /**
     * @param list<MutationVerdict> $verdicts
     */
    public function __construct(
        public array $verdicts,
    ) {}

    public function total(): int
    {
        return count($this->verdicts);
    }

    public function count(MutationOutcome $outcome): int
    {
        return count(array_filter($this->verdicts, static fn(MutationVerdict $v): bool => $v->outcome === $outcome));
    }

    /**
     * Mutants a test exercised — everything but the not-covered ones and
     * the ones declared equivalent (D-134), which no test could kill and
     * so do not count against the score.
     */
    public function covered(): int
    {
        return $this->total() - $this->count(MutationOutcome::NotCovered) - $this->count(MutationOutcome::Equivalent);
    }

    /**
     * Detected = killed + timed out + errored: the mutations the suite
     * removed, one way or another.
     */
    public function detected(): int
    {
        return $this->count(MutationOutcome::Killed)
            + $this->count(MutationOutcome::TimedOut)
            + $this->count(MutationOutcome::Errored);
    }

    /**
     * The mutation score indicator over covered mutants, 0–100. Zero when
     * nothing was covered — an honest floor, not a division by zero.
     */
    public function score(): float
    {
        $covered = $this->covered();

        return $covered === 0 ? 0.0 : $this->detected() / $covered * 100.0;
    }
}
