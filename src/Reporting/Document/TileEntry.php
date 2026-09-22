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
 * One file's tile within a `TileGrid` — raw facts only (a file's
 * path, its passed count, its total duration, its severity rank).
 * Whether a duration is worth calling out (PdfWriter's "already on
 * the Slowest list, don't repeat it" rule) is a cross-section
 * decision a full-page renderer makes, not something baked in here.
 * Column-spanning and exact pixel layout are likewise a rendering
 * concern only `PdfRenderer` needs; simpler renderers just list these.
 */
final readonly class TileEntry
{
    /**
     * @param non-empty-string $file
     */
    public function __construct(
        public string $file,
        public int $passedCount,
        public float $duration,
        public int $rank,
    ) {}
}
