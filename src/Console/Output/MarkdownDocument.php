<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Output;

use LucianoPereira\Crucible\Console\Screen\Line;

use function array_map;
use function implode;

/**
 * A rendered markdown document: the styled rows to display plus the ordered
 * list of link targets (referenced inline as `[1]`, `[2]`, …) for a viewer to
 * follow, Norton-Guides style.
 */
final readonly class MarkdownDocument
{
    /**
     * @param list<Line> $lines
     * @param list<string> $links ordered link targets; index 0 is reference [1]
     */
    public function __construct(
        public array $lines,
        public array $links,
    ) {}

    /** The plain, unstyled text of the whole document. */
    public function plainText(): string
    {
        return implode("\n", array_map(static fn(Line $line): string => $line->plainText(), $this->lines));
    }
}
