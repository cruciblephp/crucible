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
 * Which tests ended in each outcome, and why — one more listener, so it
 * sees worker results exactly like in-process ones.
 *
 * The tally on the run summary answers "how many"; the spec's
 * --display-skipped and friends answer "which ones, and what did they
 * say", which needs the reasons kept rather than counted.
 */
final class OutcomeLog implements Listener
{
    /** @var array<string, list<array{id: string, reason: ?string}>> */
    private array $byOutcome = [];

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if (!$event instanceof TestFinished) {
            return;
        }

        $this->byOutcome[$event->outcome->value][] = [
            'id'     => $event->test->toString(),
            'reason' => $event->reason,
        ];
    }

    /**
     * @return list<array{id: string, reason: ?string}>
     */
    public function of(Outcome $outcome): array
    {
        return $this->byOutcome[$outcome->value] ?? [];
    }
}
