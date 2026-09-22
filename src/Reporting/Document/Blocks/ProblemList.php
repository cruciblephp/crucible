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
use LucianoPereira\Crucible\Reporting\Document\ProblemEntry;
use LucianoPereira\Crucible\Reporting\Document\Tone;

/**
 * A numbered list of problems or untested tests. `$tone`, when given,
 * is a per-group accent for a renderer that wants one (PdfWriter's
 * severity-grouped sections, one `ProblemList` per outcome, each its
 * own tone) — renderers that show one flat, unstyled list
 * (Console/Markdown/TestDox) simply ignore it.
 */
final readonly class ProblemList implements Block
{
    /**
     * @param list<ProblemEntry> $entries
     */
    public function __construct(
        public array $entries,
        public ?Tone $tone = null,
    ) {}

    public static function sample(): self
    {
        return new self([
            new ProblemEntry('tests/unit/CacheTest.php::testRoundTrips', 'fail', 'Values do not match.'),
        ], Tone::Danger);
    }
}
