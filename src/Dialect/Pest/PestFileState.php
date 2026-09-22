<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Dialect\Pest;

use Closure;

/**
 * Everything one *.pest.php file declared, drained from the registry
 * after the file has executed. The builder folds the suite-level
 * scopes (Pest.php) into an effective state before building.
 */
final readonly class PestFileState
{
    /**
     * @param list<TestCall>         $calls
     * @param list<Closure>          $beforeEach
     * @param list<Closure>          $afterEach
     * @param list<Closure>          $beforeAll
     * @param list<Closure>          $afterAll
     * @param ?class-string          $uses
     * @param list<class-string>     $traits
     * @param list<non-empty-string> $groups file-level groups, applied to every call
     * @param list<non-empty-string> $covers  file-level covers() targets, applied to every call
     * @param list<non-empty-string> $mutates file-level mutates() targets, applied to every call
     */
    public function __construct(
        public array $calls,
        public array $beforeEach,
        public array $afterEach,
        public array $beforeAll,
        public array $afterAll,
        public ?string $uses,
        public array $traits = [],
        public array $groups = [],
        public array $covers = [],
        public array $mutates = [],
    ) {}
}
