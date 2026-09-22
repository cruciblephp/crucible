<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

/**
 * One assignment of work to one worker process: a test file and,
 * optionally, the declared names to run within it (null = the whole
 * group). Identities only — the worker rediscovers and rebuilds the
 * executable tests on its side of the process boundary; nothing is
 * ever serialized across it.
 */
final readonly class WorkUnit
{
    /**
     * @param non-empty-string        $file          project-relative path, as in TestId
     * @param ?list<non-empty-string> $tests         declared names within the file; null runs all
     * @param float                   $estimatedCost cached duration for LPT ordering; 0.0 when unknown
     */
    public function __construct(
        public string $file,
        public ?array $tests = null,
        public float $estimatedCost = 0.0,
    ) {}
}
