<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Test\TestId;

/**
 * A work unit plus what the supervisor expects back from it: the ids
 * (dataset rows included) that must appear as test:finish events on
 * the worker's stream. The expectation is what makes worker crashes
 * accountable — anything expected and unfinished is reported errored.
 */
final readonly class PlannedUnit
{
    /**
     * @param list<TestId> $expected
     * @param list<string> $dependsOn names this unit's tests depend on that no test of its own provides
     * @param list<string> $provides  names of its own tests that some other unit depends on
     * @param bool         $preserveGlobalState a test here asked for the parent's globals and constants
     */
    public function __construct(
        public WorkUnit $unit,
        public array $expected,
        public array $dependsOn = [],
        public array $provides = [],
        public bool $preserveGlobalState = false,
    ) {}

    /**
     * @param list<string> $provides
     */
    public function providing(array $provides): self
    {
        return new self($this->unit, $this->expected, $this->dependsOn, $provides, $this->preserveGlobalState);
    }
}
