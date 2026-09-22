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
 * A table-of-contents page, listing every `Heading` in the document
 * with a dotted leader to its page number. Must be the first block —
 * `PdfRenderer` only recognizes it there, since a real page number for
 * a heading that comes later can't be known without a first, discarded
 * render pass to learn where everything lands.
 */
final readonly class Toc implements Block
{
    public static function sample(): self
    {
        return new self();
    }
}
