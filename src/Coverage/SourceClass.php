<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

/**
 * One class, trait, interface, or enum as {@see SourceAnalysis} read it.
 */
final readonly class SourceClass
{
    /**
     * @param array<string, SourceMethod> $methods keyed by declared name, in declaration order
     */
    public function __construct(
        public string $name,
        public string $namespace,
        public array $methods,
    ) {}
}
