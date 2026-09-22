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
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\TestFinished;

/**
 * Feeds the result cache from the event stream — the cache is just
 * another consumer of the one output substrate (DESIGN.md D-009), not
 * a hook inside the runner. Records every test:finish and persists
 * once at run:finish.
 */
final readonly class ResultCacheWriter implements Listener
{
    /**
     * @param non-empty-string $file
     */
    public function __construct(
        private ResultCache $cache,
        private string $file,
    ) {}

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($event instanceof TestFinished) {
            $this->cache->record($event->test->toString(), $event->outcome, $event->duration);

            return;
        }

        if ($event instanceof RunFinished) {
            $this->cache->persist($this->file);
        }
    }
}
