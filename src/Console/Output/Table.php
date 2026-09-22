<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Output;

use LucianoPereira\Crucible\Console\Runtime\Runtime;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Support\Str;
use LucianoPereira\Crucible\Console\Terminal\Capabilities;

use function array_fill;
use function array_map;
use function count;
use function implode;
use function max;
use function range;
use function str_repeat;

use const PHP_EOL;

/**
 * Render a bordered, aligned table of headers and rows to the terminal.
 */
final readonly class Table
{
    private int $columns;

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     */
    public function __construct(private array $headers, private array $rows)
    {
        $this->columns = max(
            count($this->headers),
            ...array_map(count(...), $this->rows === [] ? [[]] : $this->rows),
        );
    }

    public function render(): void
    {
        Runtime::terminal()->write($this->toString());
    }

    public function toString(): string
    {
        $widths = $this->columnWidths();

        $output = $this->border($widths, '┌', '┬', '┐');

        if ($this->headers !== []) {
            $output .= $this->row($this->normalize($this->headers), $widths, true);
            $output .= $this->border($widths, '├', '┼', '┤');
        }

        foreach ($this->rows as $row) {
            $output .= $this->row($this->normalize($row), $widths, false);
        }

        return $output . $this->border($widths, '└', '┴', '┘');
    }

    /** @return array<int, int> */
    private function columnWidths(): array
    {
        $widths = array_fill(0, $this->columns, 0);

        foreach ([$this->headers, ...$this->rows] as $row) {
            foreach ($this->normalize($row) as $index => $cell) {
                $widths[$index] = max($widths[$index], Str::width($cell));
            }
        }

        return $widths;
    }

    /**
     * @param list<string> $row
     *
     * @return list<string>
     */
    private function normalize(array $row): array
    {
        if ($this->columns < 1) {
            return [];
        }

        return array_map(
            static fn(int $index): string => $row[$index] ?? '',
            range(0, $this->columns - 1),
        );
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private function row(array $cells, array $widths, bool $header): string
    {
        $rendered = '│';

        foreach ($cells as $index => $cell) {
            $padded = ' ' . Str::padRight($cell, $widths[$index]) . ' ';
            $rendered .= ($header ? $this->bold($padded) : $padded) . '│';
        }

        return $rendered . PHP_EOL;
    }

    /**
     * @param array<int, int> $widths
     */
    private function border(array $widths, string $left, string $middle, string $right): string
    {
        $segments = array_map(static fn(int $width): string => str_repeat('─', $width + 2), $widths);

        return $left . implode($middle, $segments) . $right . PHP_EOL;
    }

    private function bold(string $text): string
    {
        if (! Capabilities::color(Runtime::terminal())) {
            return $text;
        }

        return Style::none()->bold()->toAnsi() . $text . "\e[0m";
    }
}
