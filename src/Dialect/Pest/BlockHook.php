<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;

use function array_slice;
use function count;

/**
 * A beforeEach/afterEach declared inside describe(): it applies to the
 * tests of that block and of the blocks nested in it, and to no other.
 *
 * ✓ Measured against Pest 5.3.3: the block is the describe() call, not
 * its description, so a second `describe('A')` does not inherit the
 * first one's hooks; and the file's hooks, scoped or not, run in the
 * order they were declared.
 */
final readonly class BlockHook
{
    /**
     * @param list<int> $blocks the enclosing describe() calls' ids, outermost first
     */
    public function __construct(
        public Closure $hook,
        public array $blocks,
    ) {}

    /**
     * @param list<int> $blocks a test's enclosing describe() ids, outermost first
     */
    public function appliesTo(array $blocks): bool
    {
        return array_slice($blocks, 0, count($this->blocks)) === $this->blocks;
    }
}
