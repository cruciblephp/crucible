<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

/**
 * One entry in a `ProblemList` — everything shown for a single
 * problem or untested test. `$testName` is a plain string; whether a
 * renderer wraps it in code styling is that renderer's own choice
 * (Markdown backticks it, Console/TestDox don't), not baked into the
 * data.
 */
final readonly class ProblemEntry
{
    public function __construct(
        public string $testName,
        public string $outcome,
        public ?string $detail = null,
        public bool $quarantined = false,
    ) {}
}
