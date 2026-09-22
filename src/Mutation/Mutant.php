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
 * One mutation: the whole mutated source of a single file, ready to be
 * loaded in place of the original. Crucible is the *engine*, not the
 * catalog — a Mutant is an input, produced by a mutator (or an external
 * tool) and executed here. It carries only what the engine needs: the
 * file it replaces, the class that file declares (the autoload key the
 * {@see MutantApplier} intercepts), the line it changed (the key into the
 * D-077 covering-tests index), a human id for the mutator, and the
 * mutated bytes themselves.
 *
 * @see MutantApplier for how the mutated source is loaded warm
 */
final readonly class Mutant
{
    /**
     * @param non-empty-string $file          absolute path of the original file
     * @param non-empty-string $class         the FQCN the file declares — the autoload key the applier intercepts
     * @param positive-int     $line          the mutated line
     * @param non-empty-string $mutatorId     which mutator produced it (e.g. "arithmetic:+→-")
     * @param string           $mutatedSource the complete mutated file contents
     */
    public function __construct(
        public string $file,
        public string $class,
        public int $line,
        public string $mutatorId,
        public string $mutatedSource,
    ) {}
}
