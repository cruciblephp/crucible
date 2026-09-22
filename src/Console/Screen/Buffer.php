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

use function implode;

/**
 * A rectangular grid of {@see Cell}s — the back-buffer components draw into.
 *
 * Drawing is bounds-checked, style-aware and wide-character-aware. The grid is
 * the single source of truth a reconciler diffs against the screen; it also
 * renders to a string for display and snapshot testing.
 */
final class Buffer
{
    /** @var array<int, array<int, Cell>> indexed [row][column] */
    private array $rows;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
    ) {
        $this->clear();
    }

    public function clear(?Style $style = null): void
    {
        $blank = Cell::blank($style);

        $blankRow = [];
        for ($x = 0; $x < $this->width; ++$x) {
            $blankRow[] = $blank;
        }

        $rows = [];
        for ($y = 0; $y < $this->height; ++$y) {
            $rows[$y] = $blankRow;
        }

        $this->rows = $rows;
    }

    public function cellAt(int $x, int $y): Cell
    {
        return $this->rows[$y][$x] ?? Cell::blank();
    }

    /** @return array<int, Cell> */
    public function row(int $y): array
    {
        return $this->rows[$y] ?? [];
    }

    /**
     * Draw text starting at ($x, $y), returning the column after the last
     * grapheme written. Out-of-bounds writes are clipped.
     */
    public function put(int $x, int $y, string $text, ?Style $style = null): int
    {
        if ($y < 0 || $y >= $this->height) {
            return $x;
        }

        $style ??= Style::none();

        foreach (Str::graphemes($text) as $grapheme) {
            $cell = Cell::of($grapheme, $style);

            if ($x < 0) {
                $x += $cell->width;

                continue;
            }

            if ($x >= $this->width) {
                break;
            }

            if ($cell->width === 2 && $x + 1 >= $this->width) {
                $this->rows[$y][$x] = Cell::blank($style);
                ++$x;

                break;
            }

            $this->rows[$y][$x] = $cell;

            if ($cell->width === 2) {
                $this->rows[$y][$x + 1] = Cell::continuation($style);
            }

            $x += $cell->width;
        }

        return $x;
    }

    /** Render the buffer to a string, optionally with ANSI styling. */
    public function toString(bool $styled = true): string
    {
        $lines = [];

        for ($y = 0; $y < $this->height; ++$y) {
            $lines[] = $this->renderRow($y, $styled);
        }

        return implode("\n", $lines);
    }

    /** Render a single row to a string, optionally with ANSI styling. */
    public function renderRow(int $y, bool $styled = true): string
    {
        $row = $this->rows[$y] ?? [];

        return $styled ? $this->renderStyledRow($row) : $this->renderPlainRow($row);
    }

    /** @param array<int, Cell> $row */
    private function renderPlainRow(array $row): string
    {
        $line = '';

        foreach ($row as $cell) {
            if (! $cell->isContinuation) {
                $line .= $cell->char;
            }
        }

        return $line;
    }

    /** @param array<int, Cell> $row */
    private function renderStyledRow(array $row): string
    {
        $line    = '';
        $current = Style::none();

        foreach ($row as $cell) {
            if ($cell->isContinuation) {
                continue;
            }

            if (! $cell->style->equals($current)) {
                $line .= "\e[0m" . $cell->style->toAnsi();
                $current = $cell->style;
            }

            $line .= $cell->char;
        }

        return $current->isEmpty() ? $line : $line . "\e[0m";
    }
}
