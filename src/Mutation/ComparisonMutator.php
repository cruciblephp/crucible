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
 * Swaps comparison operators — the boundary and equality mutations that
 * off-by-one and sign bugs live in: `<`↔`>`, `<=`↔`>=`, `==`↔`!=`,
 * `===`↔`!==`.
 */
final class ComparisonMutator extends OperatorMutator
{
    public function id(): string
    {
        return 'comparison';
    }

    protected function swaps(): array
    {
        return [
            '<'   => '>',
            '>'   => '<',
            '<='  => '>=',
            '>='  => '<=',
            '=='  => '!=',
            '!='  => '==',
            '===' => '!==',
            '!==' => '===',
        ];
    }
}
