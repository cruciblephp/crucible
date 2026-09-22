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

use function array_unshift;

/**
 * A single row of output as an ordered list of styled text segments.
 *
 * Components build lines instead of concatenating ANSI, then draw them into a
 * {@see Buffer}; the buffer decides how (or whether) styling is emitted.
 */
final class Line
{
    /** @var list<array{text: string, style: Style}> */
    private array $segments = [];

    public function add(string $text, ?Style $style = null): self
    {
        if ($text !== '') {
            $this->segments[] = ['text' => $text, 'style' => $style ?? Style::none()];
        }

        return $this;
    }

    /** Insert a styled segment at the start of the line. */
    public function prepend(string $text, ?Style $style = null): self
    {
        if ($text !== '') {
            array_unshift($this->segments, ['text' => $text, 'style' => $style ?? Style::none()]);
        }

        return $this;
    }

    /** The visible width of the whole line. */
    public function width(): int
    {
        $width = 0;

        foreach ($this->segments as $segment) {
            $width += Str::width($segment['text']);
        }

        return $width;
    }

    /** The concatenated text of every segment, without styling. */
    public function plainText(): string
    {
        $text = '';

        foreach ($this->segments as $segment) {
            $text .= $segment['text'];
        }

        return $text;
    }

    public function drawInto(Buffer $buffer, int $x, int $y): void
    {
        foreach ($this->segments as $segment) {
            $x = $buffer->put($x, $y, $segment['text'], $segment['style']);
        }
    }
}
