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

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class UsesMethod implements CrucibleAttribute
{
    /**
     * @param class-string $className
     * @param non-empty-string $methodName
     */
    public function __construct(
        public string $className,
        public string $methodName,
    ) {}
}
