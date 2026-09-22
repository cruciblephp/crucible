<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use function max;

/**
 * The shape almost every mutator takes: swap one operator token for
 * another. A subclass supplies a table keyed by exact token text — and
 * because the tokenizer already resolves `+=`, `++`, and a `+` inside a
 * string to their own token kinds, a bare `+` text is unambiguously the
 * binary operator. Each swap preserves arity, so the mutated source is
 * always syntactically valid; no statement is ever removed.
 */
abstract class OperatorMutator implements Mutator
{
    /**
     * Token text => its replacement.
     *
     * @return array<non-empty-string, non-empty-string>
     */
    abstract protected function swaps(): array;

    public function mutate(array $tokens): array
    {
        $swaps     = $this->swaps();
        $mutations = [];

        foreach ($tokens as $index => $token) {
            $replacement = $swaps[$token->text] ?? null;

            if ($replacement !== null) {
                $mutations[] = new TokenMutation($index, $replacement, max(1, $token->line));
            }
        }

        return $mutations;
    }
}
