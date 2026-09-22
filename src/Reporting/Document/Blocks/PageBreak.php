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
 * Forces the next block onto a fresh page — how a cover composes:
 * `[Image(cover), PageBreak, Heading(title), ...]`.
 */
final readonly class PageBreak implements Block
{
    public static function sample(): self
    {
        return new self();
    }
}
