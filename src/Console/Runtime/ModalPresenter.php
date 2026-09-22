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
use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Screen\Reconciler;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Support\Sequence;
use LucianoPereira\Crucible\Console\Terminal\Terminal;

use function intdiv;
use function max;
use function min;
use function str_repeat;

/**
 * A component drawn as a dialog, centred on a surface of its own.
 *
 * ⚠ This exists because a prompt has no business writing into a screen
 * something else is already drawing. Asked inline, a question lands in
 * the middle of whatever was being rendered and leaves its own frame
 * behind afterwards; there is nothing the prompt can do about that,
 * because the prompt does not know what else is on screen.
 *
 * The alternate screen settles it without either side having to know
 * about the other: the dialog gets an empty surface, and leaving it puts
 * the terminal back byte for byte. That is the whole reason a question
 * asked over a running view can "go back to normal" at all.
 *
 * Reports {@see wrapsContent()} as true, so a {@see \LucianoPereira\Crucible\Console\Components\Prompt}
 * renders bare and this draws the only border.
 */
final class ModalPresenter implements Presenter
{
    /** Columns of empty surface kept around the dialog on each side. */
    private const int MARGIN = 4;

    /** Rows of content a dialog may have before it stops growing. */
    private const int MAX_ROWS = 20;

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

    /** The dialog's own width: the screen less its margins, and never vast. */
    public function viewportWidth(Terminal $terminal): int
    {
        return max(1, min(76, max(1, $terminal->columns()) - 2 * self::MARGIN - 4));
    }

    public function present(Terminal $terminal, Buffer $buffer): void
    {
        $screen = max(1, $terminal->columns());
        $lines  = max(1, $terminal->lines());
        $inner  = $buffer->width;
        $height = min(self::MAX_ROWS, $buffer->height);

        $box  = $inner + 4;
        $left = max(0, intdiv($screen - $box, 2));
        $top  = max(0, intdiv($lines - ($height + 2), 2));

        $frame = new Buffer($screen, $top + $height + 2);

        $this->border($left, $box, '┌', '┐')->drawInto($frame, 0, $top);

        for ($row = 0; $row < $height; $row++) {
            $line = (new Line())
                ->add(str_repeat(' ', $left))
                ->add('│ ', $this->edge());

            for ($column = 0; $column < $inner; $column++) {
                $cell = $buffer->cellAt($column, $row);
                $line->add($cell->char === '' ? ' ' : $cell->char, $cell->style);
            }

            $line->add(' │', $this->edge())->drawInto($frame, 0, $top + $row + 1);
        }

        $this->border($left, $box, '└', '┘')->drawInto($frame, 0, $top + $height + 1);

        $terminal->write(Sequence::CursorHome->render() . $this->reconciler->reconcile($frame));
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
     * A dialog owns the surface, so there is nothing to scroll past it.
     *
     * Resetting is still right: the next frame is drawn from scratch
     * rather than diffed against one the caller has invalidated.
     */
    public function interrupt(Terminal $terminal, string $text): void
    {
        $this->reconciler->reset();
    }

    public function wrapsContent(): bool
    {
        return true;
    }

    private function border(int $left, int $width, string $start, string $end): Line
    {
        return (new Line())
            ->add(str_repeat(' ', $left))
            ->add($start . str_repeat('─', max(0, $width - 2)) . $end, $this->edge());
    }

    private function edge(): Style
    {
        return Style::none()->withForeground(Theme::accent());
    }
}
