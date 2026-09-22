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
use LucianoPereira\Crucible\Console\Screen\Reconciler;
use LucianoPereira\Crucible\Console\Support\Sequence;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

use function max;

/**
 * Presents a component on the alternate screen: it owns every row while it
 * runs, and the scrollback is exactly as it was when it leaves.
 *
 * The alternate screen is the one thing that makes a full-screen view safe
 * to put in front of a build — nothing it draws is kept, so a run watched
 * this way still leaves the terminal holding whatever was there before.
 *
 * ⚠ Every frame is drawn from the home position. {@see Reconciler} works
 * in offsets from wherever the cursor sits, so without the explicit home
 * a frame that redrew fewer rows than the last would leave the anchor
 * adrift and the next diff would land in the wrong place.
 */
final class FullscreenPresenter implements Presenter
{
    private readonly Reconciler $reconciler;

    private bool $entered = false;

    public function __construct(bool $decorated = true)
    {
        $this->reconciler = new Reconciler($decorated);
    }

    public function begin(Terminal $terminal): void
    {
        if ($this->entered) {
            return;
        }

        $this->entered = true;

        $terminal->write(
            Sequence::AltScreenOn->render()
            . Sequence::HideCursor->render()
            . Sequence::EraseScreen->render()
            . Sequence::CursorHome->render(),
        );
    }

    public function viewportWidth(Terminal $terminal): int
    {
        return max(1, $terminal->columns());
    }

    /** The rows available to a component here: the whole screen. */
    public function viewportHeight(Terminal $terminal): int
    {
        return max(1, $terminal->lines());
    }

    public function present(Terminal $terminal, Buffer $buffer): void
    {
        $terminal->write(Sequence::CursorHome->render() . $this->reconciler->reconcile($buffer));
    }

    public function finish(Terminal $terminal): void
    {
        if (!$this->entered) {
            return;
        }

        $this->entered = false;

        $terminal->write(Sequence::ShowCursor->render() . Sequence::AltScreenOff->render());
    }

    /**
     * Nothing scrolls away from a screen the view owns entirely.
     *
     * A line that would have scrolled past belongs in the frame, so this
     * only forces the next present to redraw rather than smuggling text
     * into rows the component is about to overwrite.
     */
    public function interrupt(Terminal $terminal, string $text): void
    {
        $this->reconciler->reset();
    }

    public function wrapsContent(): bool
    {
        return true;
    }
}
