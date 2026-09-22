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
use LucianoPereira\Crucible\Reporting\Document\RankedEntry;
use LucianoPereira\Crucible\Reporting\PrettyName;

use function array_slice;
use function max;
use function usort;

/**
 * The slowest tests of the run, ranked descending — currently
 * PdfWriter-only content (D-091's addendum leaves it out of the other
 * three renderers rather than force an equivalent that doesn't exist
 * yet); other renderers may add their own presentation of it later.
 */
final readonly class RankedList implements Block
{
    /**
     * @param list<RankedEntry> $entries
     */
    public function __construct(
        public array $entries,
    ) {}

    /**
     * The top 10 tests at or above 1% of the run's duration (a floor
     * of 1ms on a near-instant run) — the same selection
     * `PdfWriter::outliers()` used.
     *
     * @param array<non-empty-string, list<TestFinished>> $sections
     */
    public static function build(array $sections, float $runDuration): self
    {
        $floor  = max(0.001, 0.01 * $runDuration);
        $ranked = [];

        foreach ($sections as $tests) {
            foreach ($tests as $test) {
                if ($test->duration >= $floor) {
                    $ranked[] = $test;
                }
            }
        }

        usort($ranked, static fn(TestFinished $a, TestFinished $b): int => $b->duration <=> $a->duration);

        $entries = [];

        foreach (array_slice($ranked, 0, 10) as $test) {
            $entries[] = new RankedEntry(
                PrettyName::ofTest($test->test),
                $test->test->file,
                $test->duration,
            );
        }

        return new self($entries);
    }

    public static function sample(): self
    {
        return new self([
            new RankedEntry('testRoundTrips', 'tests/unit/CacheTest.php', 0.030),
            new RankedEntry('testAdds', 'tests/unit/MathTest.php', 0.010),
        ]);
    }
}
