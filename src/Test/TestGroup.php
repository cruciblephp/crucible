<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Test;

use Closure;

/**
 * An execution group of tests sharing before-all/after-all hooks.
 * Dialect-neutral: a phpunit-dialect class, a pest describe() block,
 * and a source file of inline checks all map onto this one shape.
 *
 * A failing beforeAll errors every test in the group.
 */
final readonly class TestGroup
{
    /**
     * @param non-empty-string      $name
     * @param list<TestDefinition>  $tests
     * @param ?Closure(): void      $beforeAll
     * @param ?Closure(): void      $afterAll
     */
    public function __construct(
        public string $name,
        public array $tests,
        public ?Closure $beforeAll = null,
        public ?Closure $afterAll = null,
    ) {}
}
