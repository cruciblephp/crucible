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
use LucianoPereira\Crucible\Reporting\Document\Inline\Text;
use LucianoPereira\Crucible\Reporting\Document\Run;

final readonly class Paragraph implements Block
{
    /**
     * @param list<Run> $runs
     */
    public function __construct(
        public array $runs,
    ) {}

    public static function sample(): self
    {
        return new self([new Text('Sample paragraph text.')]);
    }
}
