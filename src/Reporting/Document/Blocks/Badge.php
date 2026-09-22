<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Reporting\Document\Block;
use LucianoPereira\Crucible\Reporting\Document\Tone;

/**
 * The run's verdict — OK, FLAKY, or FAILED. `forRun()` is the one
 * canonical rule (D-091's addendum): FAILED on any failure/error,
 * FLAKY when any test passed only on retry, OK otherwise — skips and
 * incompletes never change the verdict. Previously reimplemented in
 * `ConsoleReporter` and `PdfWriter`; `MarkdownWriter` never tracked
 * flaky at all and could only ever show a 2-way OK/Not-OK, a real gap
 * this closes rather than reproduces.
 */
final readonly class Badge implements Block
{
    public function __construct(
        public string $text,
        public Tone $tone,
    ) {}

    public static function forRun(RunSummary $summary, bool $flaky): self
    {
        return match (true) {
            $summary->failed + $summary->errored > 0 => new self('FAILED', Tone::Danger),
            $flaky                                   => new self('FLAKY', Tone::Caution),
            default                                  => new self('OK', Tone::Success),
        };
    }

    public static function sample(): self
    {
        return new self('OK', Tone::Success);
    }
}
