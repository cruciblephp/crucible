<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Runtime;

use LucianoPereira\Crucible\Console\Screen\Buffer;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

/**
 * Decides where and how a component's {@see Buffer} appears on the terminal.
 *
 * Crucible only ever uses the inline presenter (drawn in the scroll flow) —
 * no boxed/centered-dialog presenter is ported, since nothing here needs one.
 */
interface Presenter
{
    /** Prepare the terminal for drawing (e.g. hide the cursor, enter alt-screen). */
    public function begin(Terminal $terminal): void;

    /** The number of columns available for the component to draw into. */
    public function viewportWidth(Terminal $terminal): int;

    /** Reconcile and write the given frame to the terminal. */
    public function present(Terminal $terminal, Buffer $buffer): void;

    /** Restore the terminal and leave the cursor after the presented region. */
    public function finish(Terminal $terminal): void;

    /**
     * Emit a line into the scrollback above the presented region.
     *
     * A long run has both: findings that must survive as history, and a
     * frame that must stay put. Writing the finding with `print` would
     * desync the reconciler's model of the screen, so it goes through
     * here, which clears the region and forces the next present to redraw.
     */
    public function interrupt(Terminal $terminal, string $text): void;

    /**
     * Whether the presenter draws its own frame around the content. When true,
     * prompts render "bare" (without their inline gutter) so the presenter's
     * frame is the only chrome.
     */
    public function wrapsContent(): bool;
}
