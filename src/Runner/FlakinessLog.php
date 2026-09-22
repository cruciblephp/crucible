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

use function count;

/**
 * The G4 tallies, collected off the event stream like every other
 * cross-run concern — one more listener, so worker results count
 * exactly like in-process ones. Feeds the exit-code policy: flaky
 * passes (pass-on-retry) for --fail-on-flaky, and quarantined
 * failures, which are subtracted before the run is judged.
 */
final class FlakinessLog implements Listener
{
    /** @var list<non-empty-string> */
    private array $flaky = [];

    /** @var list<non-empty-string> */
    private array $quarantinedFailures = [];

    private int $quarantinedErrors = 0;

    /** @var list<non-empty-string> */
    private array $quarantinedPasses = [];

    /** @var list<non-empty-string> */
    private array $failures = [];

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if (!$event instanceof TestFinished) {
            return;
        }

        if ($event->flaky()) {
            $this->flaky[] = $event->test->toString();
        }

        if (!$event->quarantined) {
            if ($event->outcome === Outcome::Failed || $event->outcome === Outcome::Errored) {
                $this->failures[] = $event->test->toString();
            }

            return;
        }

        if ($event->outcome === Outcome::Failed || $event->outcome === Outcome::Errored) {
            $this->quarantinedFailures[] = $event->test->toString();

            if ($event->outcome === Outcome::Errored) {
                $this->quarantinedErrors++;
            }
        } elseif ($event->outcome === Outcome::Passed) {
            $this->quarantinedPasses[] = $event->test->toString();
        }
    }

    /**
     * @return list<non-empty-string>
     */
    public function flakyTests(): array
    {
        return $this->flaky;
    }

    public function flakyCount(): int
    {
        return count($this->flaky);
    }

    /**
     * @return list<non-empty-string>
     */
    public function quarantinedFailures(): array
    {
        return $this->quarantinedFailures;
    }

    public function quarantinedFailureCount(): int
    {
        return count($this->quarantinedFailures);
    }

    public function quarantinedErrorCount(): int
    {
        return $this->quarantinedErrors;
    }

    /**
     * Quarantined tests that passed — candidates for release from
     * the quarantine list.
     *
     * @return list<non-empty-string>
     */
    public function quarantinedPasses(): array
    {
        return $this->quarantinedPasses;
    }

    /**
     * Every non-quarantined failure and error, in finish order — the
     * candidates the failure-outside-the-diff check (D-048) reads.
     * Quarantined failures are excluded: they are already classified.
     *
     * @return list<non-empty-string>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
