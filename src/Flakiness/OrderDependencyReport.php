<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Flakiness;

/**
 * What the flakes hunt found (growth G4, iDFlakies vocabulary):
 * order-dependent tests reproduce with their exposing seed,
 * non-deterministic ones fail without an order to blame, and tests
 * that fail alone or in the baseline are simply broken — not flaky.
 */
final readonly class OrderDependencyReport
{
    /**
     * @param array<non-empty-string, int> $orderDependent   test id → the seed that exposes it
     * @param list<non-empty-string>       $nonDeterministic failed in a round, but neither the seed nor isolation reproduces it
     * @param list<non-empty-string>       $brokenAlone      fail even in isolation — real failures, not flakes
     * @param list<non-empty-string>       $baselineFailures already failing in declaration order — fix these first
     */
    public function __construct(
        public array $orderDependent = [],
        public array $nonDeterministic = [],
        public array $brokenAlone = [],
        public array $baselineFailures = [],
        public int $rounds = 0,
    ) {}

    public function clean(): bool
    {
        return $this->orderDependent === []
            && $this->nonDeterministic === []
            && $this->brokenAlone === []
            && $this->baselineFailures === [];
    }
}
