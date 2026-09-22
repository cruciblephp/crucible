<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * Always the last event of a stream, carrying the aggregate summary.
 */
final readonly class RunFinished implements Event
{
    /**
     * @param float $duration wall-clock seconds, from monotonic time
     * @param bool  $complete every test the suite defines finished in this run (D-071):
     *                        no selection narrowed the plan and no stop-on halted it —
     *                        the safety gate for pruning stored artifacts
     */
    public function __construct(
        public RunSummary $summary,
        public float $duration,
        public bool $complete = false,
    ) {}

    public function name(): EventName
    {
        return EventName::RunFinished;
    }

    public function payload(): array
    {
        $payload = [
            'duration' => $this->duration,
            'counts'   => $this->summary->toArray(),
        ];

        if ($this->complete) {
            $payload['complete'] = true;
        }

        // Additive: the counts map stays outcome-only (consumers sum
        // it); issue tallies ride in their own block, when present.
        if ($this->summary->hasIssues()) {
            $payload['issues'] = $this->summary->issueCounts();
        }

        // Untested (D-075) is a subset of the skip count, not an
        // outcome — like issues, it rides its own additive field so a
        // stream consumer reads the aggregate without re-counting the
        // per-test `blocked` flags.
        if ($this->summary->untested > 0) {
            $payload['untested'] = $this->summary->untested;
        }

        return $payload;
    }
}
