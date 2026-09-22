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
 * One immutable fact about a test run. Events carry only their own
 * payload; sequence number and timestamp are stamped by the Emitter
 * into the Envelope.
 */
interface Event
{
    public function name(): EventName;

    /**
     * The event's own payload fields (envelope fields excluded).
     * Optional fields are omitted, never null, so lines stay lean.
     *
     * @return array<string, mixed>
     */
    public function payload(): array;
}
