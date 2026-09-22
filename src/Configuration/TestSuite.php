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
 * One <testsuite> element of phpunit.xml, as a typed value object.
 */
final readonly class TestSuite
{
    /**
     * @param non-empty-string       $name
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $files
     * @param non-empty-string       $suffix
     */
    public function __construct(
        public string $name,
        public array $directories,
        public array $files = [],
        public string $suffix = 'Test.php',
    ) {}
}
