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

/**
 * A vector image, drawn from its own SVG markup rather than an
 * embedded raster — the vector counterpart of {@see Image}, for
 * diagrams/logos/icons where a JPEG would lose sharpness at any
 * printed size.
 */
final readonly class Svg implements Block
{
    public function __construct(
        public string $markup,
        public ?float $width = null,
    ) {}

    public static function sample(): self
    {
        return new self('<svg viewBox="0 0 100 50"><circle cx="50" cy="25" r="20" fill="#3366cc"/></svg>');
    }
}
