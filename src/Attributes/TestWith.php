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

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class TestWith implements CrucibleAttribute
{
    /**
     * @param array<mixed> $data
     * @param ?non-empty-string $name
     */
    public function __construct(
        public array $data,
        public ?string $name = null,
    ) {}
}
