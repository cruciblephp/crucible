<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\FoldingNode;
use LucianoPereira\Crucible\Reporting\Document\TileEntry;

use function uasort;

/**
 * A directory's own files, one tile each, sorted by descending
 * duration — the content `PdfWriter::tiles()` drew as a 3-column
 * grid; a renderer without a grid concept just lists these.
 */
final readonly class TileGrid implements Block
{
    /**
     * @param list<TileEntry> $tiles
     */
    public function __construct(
        public array $tiles,
    ) {}

    /**
     * @param array<non-empty-string, list<TestFinished>> $files
     */
    public static function build(array $files): self
    {
        uasort($files, static fn(array $a, array $b): int => FoldingNode::timeOf($b) <=> FoldingNode::timeOf($a));

        $tiles = [];

        foreach ($files as $file => $tests) {
            $tiles[] = new TileEntry(
                $file,
                FoldingNode::passedCountOf($tests),
                FoldingNode::timeOf($tests),
                FoldingNode::rankOf($tests),
            );
        }

        return new self($tiles);
    }

    public static function sample(): self
    {
        return new self([
            new TileEntry('tests/unit/MathTest.php', 1, 0.010, 0),
            new TileEntry('tests/unit/CacheTest.php', 0, 0.030, 2),
        ]);
    }
}
