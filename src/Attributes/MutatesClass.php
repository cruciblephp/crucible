<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;

/**
 * The class a test claims to exercise well enough to mutate. Narrows
 * `crucible mutate` to the declared source, so a suite can start
 * mutating the part it trusts instead of everything the coverage map
 * happens to reach.
 *
 * Absent everywhere, the mutation run keeps its whole covered scope —
 * declaring nothing must never mean mutating nothing.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class MutatesClass implements CrucibleAttribute
{
    /**
     * @param class-string $className
     */
    public function __construct(
        public string $className,
    ) {}
}
