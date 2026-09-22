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
use LucianoPereira\Crucible\Metadata\MetadataCollection;

/**
 * One runnable test, dialect-neutral (DESIGN.md D-008): a stable id,
 * a bound closure, and its metadata. How the closure came to exist —
 * TestCase method, pest() closure, inline #[Check] — ended at
 * discovery time; the engine sees only this.
 *
 * The closure receives the return values of this test's passed
 * dependencies (in dependency order) and returns the test's own
 * return value, feeding #[Depends] injection.
 */
final readonly class TestDefinition
{
    /**
     * @param Closure(list<mixed>): mixed $test
     * @param list<non-empty-string>      $dependencies names of tests within the
     *                                                  same group that must pass first
     */
    public function __construct(
        public TestId $id,
        public Closure $test,
        public MetadataCollection $metadata,
        public array $dependencies = [],
    ) {}
}
