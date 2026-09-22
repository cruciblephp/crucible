<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Concerns;

use Closure;

/**
 * A minimal event emitter used by prompts to register and dispatch key
 * listeners without a heavyweight dependency.
 */
trait EmitsEvents
{
    /** @var array<string, list<Closure(string): void>> */
    private array $listeners = [];

    /**
     * Register a listener for an event.
     *
     * @param Closure(string): void $callback
     */
    public function on(string $event, Closure $callback): void
    {
        $this->listeners[$event][] = $callback;
    }

    public function emit(string $event, string $payload): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }

    public function clearListeners(): void
    {
        $this->listeners = [];
    }
}
