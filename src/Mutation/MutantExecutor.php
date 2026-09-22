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
 * Runs one mutant against its covering tests and returns the verdict —
 * the seam the {@see MutationRunner} routes over. Two implementations:
 * {@see WarmMutantExecutor} (fast, forks, needs pcntl) and
 * {@see ColdMutantExecutor} (portable, spawns a worker). The runner asks
 * {@see canRun} to decide, so degradation is a routing choice, not a
 * crash: cold can always run; warm cannot re-mutate an already-loaded
 * class, and is absent entirely where the platform can't fork.
 */
interface MutantExecutor
{
    /**
     * Whether this executor can run the given mutant right now.
     */
    public function canRun(Mutant $mutant): bool;

    /**
     * @param list<non-empty-string> $coveringTestIds fastest-first (D-077)
     */
    public function execute(Mutant $mutant, array $coveringTestIds): MutationVerdict;
}
