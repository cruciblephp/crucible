<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation\Fixtures;

use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutantExecutor;
use LucianoPereira\Crucible\Mutation\MutationVerdict;

/**
 * A MutantExecutor double for the router tests: records how often it was
 * asked to execute, and whether it says it can run — so a test can assert
 * which path the {@see \LucianoPereira\Crucible\Mutation\MutationRunner} took
 * without spawning a real process.
 */
final class RecordingExecutor implements MutantExecutor
{
    public int $calls = 0;

    public function __construct(private readonly bool $canRun = true) {}

    public function canRun(Mutant $mutant): bool
    {
        return $this->canRun;
    }

    public function execute(Mutant $mutant, array $coveringTestIds): MutationVerdict
    {
        ++$this->calls;

        return MutationVerdict::escaped($mutant, 0.0);
    }
}
