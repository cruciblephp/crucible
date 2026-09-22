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
 * Swaps the boolean connectives: `&&`↔`||` (and the lower-precedence
 * `and`↔`or`). A suite that never exercises the short-circuiting branch
 * survives the flip.
 */
final class LogicalMutator extends OperatorMutator
{
    public function id(): string
    {
        return 'logical';
    }

    protected function swaps(): array
    {
        return ['&&' => '||', '||' => '&&', 'and' => 'or', 'or' => 'and'];
    }
}
