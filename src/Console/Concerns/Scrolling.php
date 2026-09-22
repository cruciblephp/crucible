<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Concerns;

use function max;
use function min;

/**
 * Maintains a scrolling viewport over a list of options, keeping a highlighted
 * item within a fixed-height window. Shared by select-style prompts.
 */
trait Scrolling
{
    /** The index of the currently highlighted item, or null when nothing is highlighted. */
    public ?int $highlighted = null;

    /** The index of the first visible item in the viewport. */
    public int $firstVisible = 0;

    /** The maximum number of items shown at once. */
    protected int $scroll = 5;

    protected function initializeScrolling(?int $highlighted = null): void
    {
        $this->highlighted = $highlighted;
        $this->scrollToHighlighted($this->totalScrollableItems());
    }

    /** The maximum number of items shown in the viewport at once. */
    public function scrollSize(): int
    {
        return $this->scroll;
    }

    protected function highlightPrevious(int $total, bool $allowNull = false): void
    {
        if ($total === 0) {
            $this->highlighted = null;
        } elseif ($this->highlighted === null) {
            $this->highlighted = $total - 1;
        } elseif ($this->highlighted === 0) {
            $this->highlighted = $allowNull ? null : $total - 1;
        } else {
            --$this->highlighted;
        }

        $this->scrollToHighlighted($total);
    }

    protected function highlightNext(int $total, bool $allowNull = false): void
    {
        if ($total === 0) {
            $this->highlighted = null;
        } elseif ($this->highlighted === null) {
            $this->highlighted = 0;
        } else {
            $next = $this->highlighted + 1;

            if ($next >= $total) {
                $this->highlighted = $allowNull ? null : 0;
            } else {
                $this->highlighted = $next;
            }
        }

        $this->scrollToHighlighted($total);
    }

    protected function highlight(?int $index): void
    {
        $this->highlighted = $index;
        $this->scrollToHighlighted($this->totalScrollableItems());
    }

    private function scrollToHighlighted(int $total): void
    {
        if ($this->highlighted === null || $this->highlighted < $this->scroll) {
            $this->firstVisible = 0;

            return;
        }

        $this->firstVisible = min(
            $this->highlighted - $this->scroll + 1,
            max(0, $total - $this->scroll),
        );
    }

    /** The number of items available to scroll through. Overridden by the host prompt. */
    abstract protected function totalScrollableItems(): int;
}
