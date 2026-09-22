<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

use LucianoPereira\Crucible\Reporting\Document\Blocks\{Badge, BulletList, FoldingTree, Heading, Image, PageBreak, Paragraph, ProblemList, ProportionBar, RankedList, Svg, TileGrid, Toc};

/**
 * Builds one canonical `Document` — one of every known block type,
 * each block's own {@see Block::sample()} — used to preview a report
 * format (`crucible extensions:preview <key>`) without a real test
 * run. Every block owns its own example next to its own code, so a
 * new block type is included the moment it implements `sample()`; this
 * class only decides the reading order, never the content.
 */
final class SampleDocument
{
    public static function build(): Document
    {
        return new Document([
            Toc::sample(),
            Heading::sample(),
            Paragraph::sample(),
            BulletList::sample(),
            Image::sample(),
            Svg::sample(),
            Badge::sample(),
            ProportionBar::sample(),
            ProblemList::sample(),
            RankedList::sample(),
            TileGrid::sample(),
            FoldingTree::sample(),
            PageBreak::sample(),
        ]);
    }
}
