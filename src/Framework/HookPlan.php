<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Framework;

/**
 * The per-test hook methods discovered from #[Before]/#[After]/
 * #[PreCondition]/#[PostCondition], already ordered by priority.
 * Execution order per test:
 *
 *   setUp -> before -> preConditions -> test -> postConditions
 *         -> after -> tearDown (after/tearDown always run)
 */
final readonly class HookPlan
{
    /**
     * @param list<non-empty-string> $before
     * @param list<non-empty-string> $preConditions
     * @param list<non-empty-string> $postConditions
     * @param list<non-empty-string> $after
     */
    public function __construct(
        public array $before = [],
        public array $preConditions = [],
        public array $postConditions = [],
        public array $after = [],
    ) {}
}
