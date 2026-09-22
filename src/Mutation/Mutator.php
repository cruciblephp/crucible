<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use PhpToken;

/**
 * Produces the mutations a rule wants to make in a token stream. The
 * catalog is a set of these; each is small, pure, and independent, so a
 * new rule is a new class, never a change to the generator. A mutation is
 * a point, not a whole file — {@see MutantGenerator} rebuilds the source.
 */
interface Mutator
{
    /**
     * A stable, human-readable id (e.g. "arithmetic").
     *
     * @return non-empty-string
     */
    public function id(): string;

    /**
     * @param list<PhpToken> $tokens
     *
     * @return list<TokenMutation>
     */
    public function mutate(array $tokens): array;
}
