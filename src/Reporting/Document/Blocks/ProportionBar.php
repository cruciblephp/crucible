<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\ProportionShare;
use LucianoPereira\Crucible\Reporting\Document\Tone;

/**
 * The passed/skipped/flagged/failed share strip — a PDF-only visual
 * with no text equivalent that reads well, so every other renderer
 * simply ignores it (degradation is a per-renderer decision, D-091's
 * addendum).
 */
final readonly class ProportionBar implements Block
{
    /**
     * @param list<ProportionShare> $shares
     */
    public function __construct(
        public array $shares,
    ) {}

    public static function sample(): self
    {
        return new self([
            new ProportionShare(2, Tone::Success),
            new ProportionShare(1, Tone::Danger),
        ]);
    }
}
