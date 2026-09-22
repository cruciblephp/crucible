<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

/**
 * The <source> element of phpunit.xml — which application code the
 * test run considers "own code" (coverage, deprecation attribution).
 */
final readonly class Source
{
    /**
     * @param list<non-empty-string> $includeDirectories
     * @param list<non-empty-string> $includeFiles
     * @param list<non-empty-string> $excludeDirectories
     * @param list<non-empty-string> $excludeFiles
     */
    public function __construct(
        public array $includeDirectories = [],
        public array $includeFiles = [],
        public array $excludeDirectories = [],
        public array $excludeFiles = [],
    ) {}

    public function notEmpty(): bool
    {
        return $this->includeDirectories !== [] || $this->includeFiles !== [];
    }
}
