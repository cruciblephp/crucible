<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Screen;

use LucianoPereira\Crucible\Console\Support\Sequence;

use function count;
use function implode;

/**
 * Turns successive {@see Buffer}s into the minimal escape sequence needed to
 * update the screen.
 *
 * The cursor is assumed to sit at the region's top-left ("anchor") before and
 * after each call: the reconciler only ever moves relative to that anchor and
 * always parks the cursor back there, so callers never track absolute position.
 */
final class Reconciler
{
    private ?Buffer $previous = null;

    public function __construct(
        private readonly bool $decorated = true,
    ) {}

    /** Forget the previous frame, forcing the next reconcile to be a full draw. */
    public function reset(): void
    {
        $this->previous = null;
    }

    public function reconcile(Buffer $next): string
    {
        $previous       = $this->previous;
        $this->previous = $next;

        $sameSize = $previous instanceof \LucianoPereira\Crucible\Console\Screen\Buffer
            && $previous->width === $next->width
            && $previous->height === $next->height;

        return $sameSize
            ? $this->diff($previous, $next)
            : $this->full($next);
    }

    private function full(Buffer $next): string
    {
        $rows = [];
        for ($y = 0; $y < $next->height; ++$y) {
            $rows[] = $next->renderRow($y, $this->decorated);
        }

        $out = "\r" . Sequence::EraseDown->render() . implode("\n", $rows);

        if ($next->height > 1) {
            $out .= Sequence::CursorUp->render($next->height - 1);
        }

        return $out . "\r";
    }

    private function diff(Buffer $previous, Buffer $next): string
    {
        $out = '';

        for ($y = 0; $y < $next->height; ++$y) {
            if ($this->rowEquals($previous->row($y), $next->row($y))) {
                continue;
            }

            $out .= Sequence::CursorDown->render($y)
                . "\r"
                . Sequence::EraseLine->render()
                . $next->renderRow($y, $this->decorated)
                . "\r"
                . Sequence::CursorUp->render($y);
        }

        return $out;
    }

    /**
     * @param array<int, Cell> $a
     * @param array<int, Cell> $b
     */
    private function rowEquals(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $x => $cell) {
            if (! isset($b[$x]) || ! $cell->equals($b[$x])) {
                return false;
            }
        }

        return true;
    }
}
