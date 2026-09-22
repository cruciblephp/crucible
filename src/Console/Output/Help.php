<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Output;

use LucianoPereira\Crucible\Console\Style\Brand;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Support\Str;

use function array_filter;
use function array_slice;
use function count;
use function explode;
use function implode;
use function intdiv;
use function max;
use function min;
use function preg_match;
use function rtrim;
use function str_contains;
use function str_repeat;
use function trim;

/**
 * Lays out `--help` as terms and descriptions in two columns.
 *
 * ⚠ The text itself stays one readable block in the source, because
 * that is where it is edited and reviewed. What it cannot carry there
 * is alignment: a description written two spaces after its term lands
 * at a different column on every row, and 55 of them did. Column
 * position is a rendering concern, so it is decided here, once, from
 * the terms actually present.
 */
final readonly class Help
{
    /**
     * The column every description starts at.
     *
     * One column for the whole document, not one per indent level: a
     * sub-option's description lining up with its parent's is the
     * entire point, and it is why this class exists.
     *
     * ✓ 36 measured against the real help: of 191 term rows, 44 overran
     * a column of 28 and only 10 overrun this one. Fitting all 191 takes
     * 60 columns — `--do-not-warn-when-php-is-not-configured-for-development`
     * alone is 56 — which leaves 20 for the descriptions on an 80-column
     * screen. The curve flattens here.
     */
    private const int COLUMN = 36;

    /** Share of a narrow screen the term column may take before it starves the text. */
    private const int SHARE = 45;

    /** Columns a description needs before a wider term column stops being worth it. */
    private const int READABLE = 30;

    /** The narrowest screen still laid out in two columns. */
    private const int MIN_WIDTH = 50;

    /** What a term looks like: a flag, or a `crucible <command>`. */
    private const string TERM_PATTERN = '/^(\s+)((?:--?[a-z0-9-]+(?:,\s*-[a-z])?(?:\[?=?\s?<[^>]+>\]?)?|crucible\s+[a-z-]+(?:\s+\[[^\]]+\])?))\s\s+(\S.*)$/';

    /**
     * A bare word naming a value rather than a flag — a view key, say.
     *
     * ⚠ Only accepted well inside the margin. Flush against it, this
     * would match the opening word of any sentence that happened to
     * carry a double space, and lay a paragraph out as a table.
     */
    private const string VALUE_PATTERN = '/^(\s{6,})([a-z][a-z0-9-]*)\s\s+(\S.*)$/';

    public function __construct(
        private int $width = 80,
        private bool $decorated = true,
    ) {}

    public function render(string $text): string
    {
        $lines   = explode("\n", $text);
        $columns = $this->columns($lines);
        $out     = [];

        foreach ($lines as $line) {
            foreach ($this->line($line, $columns) as $rendered) {
                $out[] = $rendered;
            }
        }

        return implode("\n", $out);
    }

    /**
     * The description column for each indent that needs its own.
     *
     * ⚠ Only indents starting at or past the shared column get one — a
     * list nested under an option, whose terms begin where everything
     * else's descriptions do. Giving every indent its own column is
     * what produced the ragged three-column output this replaces.
     *
     * @param list<string> $lines
     *
     * @return array<int, int>
     */
    private function columns(array $lines): array
    {
        $shared = $this->column();
        $groups = [];

        foreach ($lines as $line) {
            $parts = $this->split($line);

            if ($parts === null) {
                continue;
            }

            [$indent, $term] = $parts;

            $groups[Str::width($indent)][] = Str::width($indent) + Str::width($term) + 2;
        }

        $columns = [];

        foreach ($groups as $at => $ends) {
            $overflowing = count(array_filter($ends, static fn(int $end): bool => $end > $shared));

            // ⚠ A group keeps the shared column while its overflows are
            // outliers — the main option list has a handful of very long
            // flags among a hundred ordinary ones, and widening for them
            // would starve every description. A group where most terms
            // overflow is not an outlier, it is a list that does not fit,
            // and it gets a column of its own.
            $local = max($ends);

            // ⚠ And only while the description still has room to be
            // read. A group of two whose second term is sixty columns
            // wide is not a list that needs its own column; it is one
            // long term, and long terms take their own line.
            if ($overflowing > 0 && $overflowing * 2 >= count($ends) && $local <= $this->width - self::READABLE) {
                $columns[$at] = $local;
            }
        }

        return $columns;
    }

    /**
     * @param array<int, int> $columns
     *
     * @return list<string>
     */
    private function line(string $line, array $columns): array
    {
        if ($line === '') {
            return [''];
        }

        // A heading: flush left, ending in a colon, nothing else on it.
        if (preg_match('/^([A-Z][A-Za-z0-9 ]*):$/', $line, $heading) === 1) {
            return [$this->paint($heading[1] . ':', Style::none()->bold())];
        }

        $parts = $this->split($line);

        if ($parts === null) {
            return [rtrim($line)];
        }

        [$indent, $term, $description] = $parts;

        $column = $columns[Str::width($indent)] ?? $this->column();
        $room   = max(20, $this->width - $column);

        if ($this->width < self::MIN_WIDTH) {
            return [$indent . $this->paint($term, $this->accent()), $indent . '  ' . $description];
        }

        // ⚠ A description with nowhere to break is left whole and
        // allowed to overrun. These are option names, and Str::wrap()
        // splits on width rather than on words: broken across two lines
        // a flag cannot be copied, read, or searched for, which is
        // worse than a line running past the margin.
        $wrapped = str_contains(trim($description), ' ')
            ? Str::wrap($description, $room)
            : [$description];
        $head = $wrapped[0] ?? '';
        $rest = array_slice($wrapped, 1);

        // A term too wide for its column keeps the column rather than
        // pushing its own description out of line with every other one.
        $lines = Str::width($indent . $term) + 1 > $column
            ? [$indent . $this->paint($term, $this->accent()), str_repeat(' ', $column) . $head]
            : [$indent . $this->paint($term, $this->accent()) . str_repeat(' ', $column - Str::width($indent . $term)) . $head];

        foreach ($rest as $continuation) {
            $lines[] = str_repeat(' ', $column) . $continuation;
        }

        return $lines;
    }

    /**
     * The shared column, given back to the text on a narrow screen.
     *
     * A fixed 36 on a 60-column terminal leaves 24 for descriptions,
     * which wraps every one of them to three lines. The column yields
     * first, because a term can take its own line and prose cannot.
     */
    private function column(): int
    {
        return min(self::COLUMN, max(20, intdiv($this->width * self::SHARE, 100)));
    }

    /**
     * A line's indent, term and description, or null if it is prose.
     *
     * @return array{string, string, string}|null
     */
    private function split(string $line): ?array
    {
        foreach ([self::TERM_PATTERN, self::VALUE_PATTERN] as $pattern) {
            if (preg_match($pattern, $line, $parts) === 1) {
                return [$parts[1], $parts[2], $parts[3]];
            }
        }

        return null;
    }

    private function accent(): Style
    {
        return Style::none()->withForeground(Brand::flame());
    }

    private function paint(string $text, Style $style): string
    {
        return $this->decorated ? $style->toAnsi() . $text . "\e[0m" : $text;
    }
}
