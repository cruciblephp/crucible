<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

/** One colored segment of a `ProportionBar` — a share of the total, and its tone. */
final readonly class ProportionShare
{
    public function __construct(
        public int $count,
        public Tone $tone,
    ) {}
}
