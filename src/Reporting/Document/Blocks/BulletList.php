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

final readonly class BulletList implements Block
{
    /**
     * @param list<list<Run>> $items
     */
    public function __construct(
        public array $items,
    ) {}

    public static function sample(): self
    {
        return new self([
            [new Text('First item')],
            [new Text('Second item')],
        ]);
    }
}
