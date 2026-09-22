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

/** Emphasized inline text. */
final readonly class Strong implements Run
{
    public function __construct(
        public string $content,
    ) {}
}
