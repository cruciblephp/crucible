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
 * Swaps arithmetic operators: `+`↔`-`, `*`↔`/`, and `%`→`*`. A test that
 * only ever checks one side of a sum will not notice `+` become `-`.
 */
final class ArithmeticMutator extends OperatorMutator
{
    public function id(): string
    {
        return 'arithmetic';
    }

    protected function swaps(): array
    {
        return ['+' => '-', '-' => '+', '*' => '/', '/' => '*', '%' => '*'];
    }
}
