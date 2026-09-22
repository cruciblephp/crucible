<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Snapshot;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\TestFinished;

/**
 * Tallies what an --update-snapshots run recorded (D-066) — an
 * event-stream listener like IssueLog/FlakinessLog, so worker runs
 * count identically to in-process ones: the per-test counts ride the
 * test:finish payload, the supervisor re-emits, this sums.
 */
final class SnapshotLog implements Listener
{
    private int $created = 0;

    private int $updated = 0;

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished && $event->snapshots !== null) {
            $this->created += $event->snapshots['created'];
            $this->updated += $event->snapshots['updated'];
        }
    }

    public function created(): int
    {
        return $this->created;
    }

    public function updated(): int
    {
        return $this->updated;
    }
}
