<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use DateTimeImmutable;

/**
 * An event as it appears on the stream: the event itself plus the
 * envelope fields stamped by the Emitter.
 *
 * The schema is versioned from day one — the lesson from Rust's
 * libtest-json stabilization pain and Node's "do not parse reporter
 * output" warning: tooling grows on whatever format exists, so the
 * format must announce what it is.
 */
final readonly class Envelope
{
    public const int SCHEMA_VERSION = 1;

    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param positive-int $sequence 1-based, gapless, per stream
     */
    public function __construct(
        public int $sequence,
        public DateTimeImmutable $timestamp,
        public Event $event,
    ) {}

    /**
     * Envelope fields first (ver, event, ts, seq), then the event's
     * own payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ver'   => self::SCHEMA_VERSION,
            'event' => $this->event->name()->value,
            'ts'    => $this->timestamp->format(self::TIMESTAMP_FORMAT),
            'seq'   => $this->sequence,
        ] + $this->event->payload();
    }
}
