<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use Random\Randomizer;

use function min;

/**
 * The retry shape (D-043, nextest's config semantics): how many extra
 * attempts, and how long to wait between them — fixed or exponential
 * backoff from a base delay, optionally capped, optionally jittered
 * (a uniform factor in [0.5, 1], the classic thundering-herd
 * spreader for network-flavored tests).
 *
 * Jitter randomizes *timing only* — no outcome ever depends on it,
 * which is why it does not violate the determinism principle and
 * needs no seed plumbing.
 */
final readonly class RetryPolicy
{
    /**
     * @param int<0, max> $count           extra attempts after the first failure
     * @param float       $delaySeconds    base pause before a retry
     * @param ?float      $maxDelaySeconds cap for exponential growth
     */
    public function __construct(
        public int $count = 0,
        public Backoff $backoff = Backoff::None,
        public float $delaySeconds = 0.0,
        public ?float $maxDelaySeconds = null,
        public bool $jitter = false,
    ) {}

    public static function none(): self
    {
        return new self();
    }

    /**
     * The same shape with a different attempt budget — how a CLI
     * `--retries N` overrides the count while the configured backoff
     * survives.
     *
     * @param int<0, max> $count
     */
    public function withCount(int $count): self
    {
        return new self($count, $this->backoff, $this->delaySeconds, $this->maxDelaySeconds, $this->jitter);
    }

    /**
     * Seconds to wait before the given retry (1 = the first retry).
     *
     * @param positive-int $retry
     */
    public function delayFor(int $retry, Randomizer $randomizer = new Randomizer()): float
    {
        $delay = match ($this->backoff) {
            Backoff::None        => 0.0,
            Backoff::Fixed       => $this->delaySeconds,
            Backoff::Exponential => $this->delaySeconds * 2 ** ($retry - 1),
        };

        if ($this->maxDelaySeconds !== null) {
            $delay = min($delay, $this->maxDelaySeconds);
        }

        if ($this->jitter && $delay > 0.0) {
            $delay *= 0.5 + $randomizer->getFloat(0.0, 0.5);
        }

        return $delay;
    }
}
