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
 * Presents a component inline, in the normal scroll flow: the frame is drawn
 * where the cursor is and redrawn in place as it changes, leaving a compact
 * record above once finished.
 */
final class InlinePresenter implements Presenter
{
    private readonly Reconciler $reconciler;

    private int $lastHeight = 1;

    public function __construct(bool $decorated = true)
    {
        $this->reconciler = new Reconciler($decorated);
    }

    public function begin(Terminal $terminal): void
    {
        $terminal->write(Sequence::HideCursor->render());
    }

    public function viewportWidth(Terminal $terminal): int
    {
        return max(1, $terminal->columns());
    }

    public function present(Terminal $terminal, Buffer $buffer): void
    {
        $this->lastHeight = $buffer->height;
        $terminal->write($this->reconciler->reconcile($buffer));
    }

    public function finish(Terminal $terminal): void
    {
        $terminal->write(
            Sequence::CursorDown->render($this->lastHeight - 1)
            . "\n"
            . Sequence::ShowCursor->render(),
        );
    }

    public function interrupt(Terminal $terminal, string $text): void
    {
        // The cursor is parked at the region's anchor, so EraseDown takes
        // the whole frame; the text then scrolls the anchor down one line
        // and the reset makes the next present a full draw there.
        $terminal->write("\r" . Sequence::EraseDown->render() . $text . "\n");

        $this->reconciler->reset();
    }

    public function wrapsContent(): bool
    {
        return false;
    }
}
