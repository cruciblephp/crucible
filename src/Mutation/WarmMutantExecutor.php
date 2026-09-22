<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use Closure;

/**
 * The warm executor: runs a mutant in a forked child via {@see
 * MutantApplier}, running the covering tests in-process. It adapts the
 * applier's callback shape to the {@see MutantExecutor} seam — the runner
 * hands it covering ids, and the injected in-process runner turns those
 * into the first killer (or null). Only ever constructed where the
 * platform can fork ({@see MutantApplier::isSupported}); elsewhere the
 * runner has no warm executor and routes everything cold.
 */
final readonly class WarmMutantExecutor implements MutantExecutor
{
    /**
     * @param Closure(list<non-empty-string>): ?non-empty-string $runCovering runs the covering ids in the
     *                                                                        forked child, returns the first killer
     */
    public function __construct(
        private MutantApplier $applier,
        private Closure $runCovering,
        private float $timeoutSeconds = 10.0,
    ) {}

    /**
     * Warm can run a mutant only while its class is still unloaded — a
     * fork inherits the parent's declarations, so a loaded class can't be
     * re-mutated. The runner then routes it cold.
     */
    public function canRun(Mutant $mutant): bool
    {
        return $this->applier->canApply($mutant);
    }

    public function execute(Mutant $mutant, array $coveringTestIds): MutationVerdict
    {
        $runCovering = $this->runCovering;

        return $this->applier->run(
            $mutant,
            static fn(): ?string => $runCovering($coveringTestIds),
            $this->timeoutSeconds,
        );
    }
}
