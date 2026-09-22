<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Screen;

use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Support\Str;

use function max;

/**
 * A single terminal cell: one grapheme and its style.
 *
 * A wide grapheme occupies two columns; the column immediately to its right is
 * represented by a {@see Cell::continuation()} placeholder so the grid stays
 * rectangular.
 */
final readonly class Cell
{
    public int $width;

    private function __construct(
        public string $char,
        public Style $style,
        public bool $isContinuation,
    ) {
        $this->width = $isContinuation ? 0 : max(1, Str::charWidth($char));
    }

    public static function blank(?Style $style = null): self
    {
        return new self(' ', $style ?? Style::none(), false);
    }

    public static function of(string $char, ?Style $style = null): self
    {
        return new self($char === '' ? ' ' : $char, $style ?? Style::none(), false);
    }

    /** The right half of a preceding wide grapheme. */
    public static function continuation(Style $style): self
    {
        return new self('', $style, true);
    }

    public function equals(self $other): bool
    {
        return $this->char === $other->char
            && $this->isContinuation === $other->isContinuation
            && $this->style->equals($other->style);
    }
}
