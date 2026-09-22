<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Components;

use LucianoPereira\Crucible\Console\Input\Event;
use LucianoPereira\Crucible\Console\Screen\Buffer;

/**
 * A self-contained, modal renderable driven by the runtime.
 *
 * A component knows nothing about the terminal, presentation, or escape codes:
 * it measures how tall it wants to be for a given width, draws itself into a
 * {@see Buffer}, and reacts to {@see Event}s until it reports itself finished.
 */
interface Component
{
    /** The number of rows this component needs at the given width. */
    public function measure(int $width): int;

    /** Draw the component into the (already correctly-sized) buffer. */
    public function render(Buffer $buffer): void;

    /** React to an event, updating internal state. */
    public function handle(Event $event): void;

    /** Whether the component has produced its final result. */
    public function isFinished(): bool;

    /** The component's result. Only meaningful once {@see isFinished()} is true. */
    public function result(): mixed;
}
