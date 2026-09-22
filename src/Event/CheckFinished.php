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
 * A run-scoped check finished: the outcome of Crucible testing an
 * artifact that is about the whole project, not a single location — a
 * command gate (larastan, whole-suite phpcpd), where there is no file
 * to anchor a `test:finish` node to.
 *
 * A check is not a test: it tallies separately, never enters the file
 * tree or the shard hash, and is orthogonal to the D-071 `complete`
 * gate. But a failing/errored check does vote the exit code, with its
 * named reason (never a bare non-zero). Emitted inside the run bracket
 * — after the suite, before `run:finish`.
 */
final readonly class CheckFinished implements Event
{
    /**
     * @param non-empty-string  $name     the check's label (the gate name), for attribution
     * @param float             $duration wall-clock seconds, from monotonic time
     * @param ?non-empty-string $reason   why it failed or errored; omitted when passed
     */
    public function __construct(
        public string $name,
        public Outcome $outcome,
        public float $duration,
        public ?string $reason = null,
    ) {}

    public function name(): EventName
    {
        return EventName::CheckFinished;
    }

    public function payload(): array
    {
        $payload = [
            'name'     => $this->name,
            'outcome'  => $this->outcome->value,
            'duration' => $this->duration,
        ];

        if ($this->reason !== null) {
            $payload['reason'] = $this->reason;
        }

        return $payload;
    }
}
