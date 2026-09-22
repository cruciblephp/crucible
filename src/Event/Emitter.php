<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use LucianoPereira\Crucible\Clock\Clock;

/**
 * The single writer of the event stream. Stamps each event with a
 * gapless 1-based sequence number and a timestamp, then delivers it
 * to every listener synchronously, in subscription order.
 *
 * Stream discipline (enforced by the runner, asserted by consumers):
 * the first emitted event is run:start, the last is run:finish.
 */
final class Emitter
{
    /** @var int<0, max> */
    private int $sequence = 0;

    /** @var list<Listener> */
    private array $listeners = [];

    public function __construct(
        private readonly Clock $clock,
    ) {}

    public function subscribe(Listener $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function emit(Event $event): Envelope
    {
        $envelope = new Envelope(++$this->sequence, $this->clock->now(), $event);

        foreach ($this->listeners as $listener) {
            $listener->handle($envelope);
        }

        return $envelope;
    }
}
