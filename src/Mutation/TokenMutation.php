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
 * One point a mutator wants to change: the token to replace (by position
 * in the stream), the text to replace it with, and the line it sits on.
 * The generator turns each of these into a whole mutated source.
 */
final readonly class TokenMutation
{
    /**
     * @param int<0, max>      $index       position of the token in the stream
     * @param non-empty-string $replacement the text that replaces it
     * @param positive-int     $line        the source line, for reporting and the covering-tests query
     * @param positive-int     $span        how many tokens from $index the replacement stands for (D-134):
     *                                      1 for an operator swap, more for a whole array element dropped
     */
    public function __construct(
        public int $index,
        public string $replacement,
        public int $line,
        public int $span = 1,
    ) {}
}
