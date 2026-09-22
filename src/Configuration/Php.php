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
 * The <php> element of phpunit.xml — ini settings, environment
 * variables, and constants applied before the test run.
 */
final readonly class Php
{
    /**
     * @param array<non-empty-string, string>                     $ini
     * @param array<non-empty-string, string>                     $env
     * @param array<non-empty-string, bool|float|int|string|null> $constants
     */
    public function __construct(
        public array $ini = [],
        public array $env = [],
        public array $constants = [],
    ) {}
}
