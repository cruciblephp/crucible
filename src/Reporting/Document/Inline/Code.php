<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Inline;

use LucianoPereira\Crucible\Reporting\Document\Run;

/** Monospace inline text — a test name, a file path. */
final readonly class Code implements Run
{
    public function __construct(
        public string $content,
    ) {}
}
