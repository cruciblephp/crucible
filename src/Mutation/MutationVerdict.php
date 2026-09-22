<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

/**
 * The result of running one mutant: its {@see MutationOutcome}, the test
 * that killed it (when killed), a reason (when errored), and how long the
 * attempt took. Named constructors keep the invalid states unrepresentable
 * — only a killed verdict carries a killer, only an errored one a reason.
 */
final readonly class MutationVerdict
{
    /**
     * @param ?non-empty-string $killedBy the id of the test that killed the mutant
     * @param ?non-empty-string $reason   why the mutant errored
     */
    private function __construct(
        public Mutant $mutant,
        public MutationOutcome $outcome,
        public ?string $killedBy = null,
        public ?string $reason = null,
        public float $duration = 0.0,
    ) {}

    /**
     * @param non-empty-string $testId
     */
    public static function killed(Mutant $mutant, string $testId, float $duration): self
    {
        return new self($mutant, MutationOutcome::Killed, killedBy: $testId, duration: $duration);
    }

    public static function escaped(Mutant $mutant, float $duration): self
    {
        return new self($mutant, MutationOutcome::Escaped, duration: $duration);
    }

    /**
     * @param non-empty-string $reason
     */
    public static function errored(Mutant $mutant, string $reason, float $duration): self
    {
        return new self($mutant, MutationOutcome::Errored, reason: $reason, duration: $duration);
    }

    public static function timedOut(Mutant $mutant, float $duration): self
    {
        return new self($mutant, MutationOutcome::TimedOut, duration: $duration);
    }

    /**
     * No test covered the mutated line — decided before any process is
     * spawned, so it carries no duration.
     */
    public static function notCovered(Mutant $mutant): self
    {
        return new self($mutant, MutationOutcome::NotCovered);
    }
}
