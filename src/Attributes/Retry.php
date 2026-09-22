<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Attributes;

use Attribute;
use LucianoPereira\Crucible\Metadata\CrucibleAttribute;
use LucianoPereira\Crucible\Runner\Backoff;
use LucianoPereira\Crucible\Runner\RetryPolicy;

/**
 * Re-run this test up to $count extra times when it fails or errors
 * (growth G4, nextest retry semantics). A pass on a retry is not
 * green — it is classified FLAKY and reported as such. Overrides the
 * run-wide --retries/->retries() policy for this test, backoff shape
 * included (D-043).
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class Retry implements CrucibleAttribute
{
    /**
     * @param int<0, max> $count           extra attempts after the first failure
     * @param float       $delaySeconds    base pause before a retry
     * @param ?float      $maxDelaySeconds cap for exponential growth
     */
    public function __construct(
        public int $count,
        public Backoff $backoff = Backoff::None,
        public float $delaySeconds = 0.0,
        public ?float $maxDelaySeconds = null,
        public bool $jitter = false,
    ) {}

    public function policy(): RetryPolicy
    {
        return new RetryPolicy($this->count, $this->backoff, $this->delaySeconds, $this->maxDelaySeconds, $this->jitter);
    }
}
