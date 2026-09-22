<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;

/**
 * How each test ended and how long it took, in finish order — the one
 * thing CoverageData does not carry.
 *
 * Coverage records which test touched which line, which is a different
 * question from how that test ended: a line covered by a failing test is
 * still covered. The XML coverage index reports both, so the outcome has
 * to arrive from the stream rather than the coverage map. A listener, so
 * worker results are seen exactly like in-process ones.
 */
final class TestRecordLog implements Listener
{
    /** @var list<array{id: string, status: string, time: float}> */
    private array $records = [];

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if (!$event instanceof TestFinished) {
            return;
        }

        $this->records[] = [
            'id'     => $event->test->toString(),
            'status' => $this->status($event->outcome),
            'time'   => $event->duration,
        ];
    }

    /**
     * @return list<array{id: string, status: string, time: float}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Crucible's outcome vocabulary in the words the XML index uses.
     * Named for the reader of the document, not for the enum.
     */
    private function status(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::Passed     => 'success',
            Outcome::Failed     => 'failure',
            Outcome::Errored    => 'error',
            Outcome::Skipped    => 'skipped',
            Outcome::Incomplete => 'incomplete',
            Outcome::Risky      => 'risky',
        };
    }
}
