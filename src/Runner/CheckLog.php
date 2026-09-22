<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\CheckFinished;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;

use function array_filter;
use function array_values;
use function count;

/**
 * Collects every run-scoped check off the event stream — one more
 * listener, so it sees checks emitted from the runner's after-tests
 * hook exactly like any other event (the IssueLog precedent). The
 * exit-code policy and the "checks" summary line both read from here
 * after the run.
 */
final class CheckLog implements Listener
{
    /** @var list<CheckFinished> */
    private array $checks = [];

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof CheckFinished) {
            $this->checks[] = $event;
        }
    }

    /**
     * @return list<CheckFinished>
     */
    public function checks(): array
    {
        return $this->checks;
    }

    /**
     * The checks that vote the exit code — failed or errored — with
     * their named reasons.
     *
     * @return list<CheckFinished>
     */
    public function failing(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn(CheckFinished $check): bool => $check->outcome === Outcome::Failed
                || $check->outcome === Outcome::Errored,
        ));
    }

    public function count(): int
    {
        return count($this->checks);
    }
}
