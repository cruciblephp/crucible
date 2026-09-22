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
 * A consumer of the event stream: writers, reporters, caches,
 * third-party tools. Listeners receive every envelope, in order,
 * synchronously — the single-writer guarantee lives in the Emitter,
 * not in the listeners.
 */
interface Listener
{
    public function handle(Envelope $envelope): void;
}
