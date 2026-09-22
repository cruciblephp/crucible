<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

/**
 * The structured expected/actual pair of a failed comparison, in
 * exported (serialized) form, plus the rendered line diff.
 */
final readonly class ComparisonFailure
{
    public function __construct(
        public string $expected,
        public string $actual,
        public string $diff,
    ) {}
}
