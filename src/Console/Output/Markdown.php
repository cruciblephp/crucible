<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Output;

use LucianoPereira\Crucible\Console\Screen\Line;
use LucianoPereira\Crucible\Console\Style\Style;
use LucianoPereira\Crucible\Console\Style\Theme;
use LucianoPereira\Crucible\Console\Support\Str;

use function array_fill;
use function array_map;
use function array_shift;
use function count;
use function explode;
use function implode;
use function max;
use function preg_match;
use function preg_match_all;
use function rtrim;
use function str_contains;
use function str_repeat;
use function str_replace;
use function strlen;
use function substr;

use const PREG_OFFSET_CAPTURE;
use const PREG_SET_ORDER;

/**
 * Renders a practical subset of Markdown to styled {@see Line}s: headings,
 * bold/italic/code inline spans, bullet lists, block quotes, horizontal rules,
 * fenced code, and `[text](target)` links (collected as numbered references).
 */
final class Markdown
{
    private function __construct() {}

    public static function render(string $markdown, int $width = 80): MarkdownDocument
    {
        $lines  = [];
        $links  = [];
        $inCode = false;

        // A paragraph or bullet item is source-wrapped across several
        // physical lines (READMEs hard-wrap at ~80-100 columns); inline
        // spans like **bold** routinely straddle that break, and a
        // bullet's continuation lines (indented, no `-`/`*`) would
        // otherwise fall through as their own unindented paragraph.
        // Buffer consecutive lines belonging to the same block and join
        // them before parsing inline markup and wrapping to width.
        $paragraph = [];
        $kind      = 'text';

        /** @var list<string> $table raw `|`-delimited rows, rendered as a block once the run ends */
        $table = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $markdown)) as $raw) {
            $text = rtrim($raw);

            if (preg_match('/^\s*```/', $text) === 1) {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                self::flushTable($table, $lines, $links);
                $inCode = ! $inCode;

                continue;
            }

            if ($inCode) {
                $lines[] = (new Line())->add($text === '' ? ' ' : $text, self::style('code'));

                continue;
            }

            if (preg_match('/^\s*\|/', $text) === 1) {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                $table[] = \trim($text);

                continue;
            }

            self::flushTable($table, $lines, $links);

            if ($text === '') {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                $lines[] = new Line();
            } elseif (preg_match('/^(#{1,3})\s+(.*)$/', $text, $m) === 1) {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);

                foreach (Str::wrap($m[2], $width) as $piece) {
                    $lines[] = (new Line())->add($piece, self::heading(strlen($m[1])));
                }
            } elseif (preg_match('/^\s*(?:-{3,}|\*{3,})\s*$/', $text) === 1) {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                $lines[] = (new Line())->add(str_repeat('─', $width), self::style('dim'));
            } elseif (preg_match('/^\s*[-*]\s+(.*)$/', $text, $m) === 1) {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                $paragraph[] = $m[1];
                $kind        = 'bullet';
            } elseif (preg_match('/^>\s?(.*)$/', $text, $m) === 1) {
                self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                $paragraph[] = $m[1];
                $kind        = 'quote';
            } elseif ($kind === 'bullet' && preg_match('/^\s{2,}\S/', $text) === 1) {
                $paragraph[] = \trim($text);
            } else {
                if ($kind !== 'text') {
                    self::flushParagraph($paragraph, $kind, $lines, $links, $width);
                }

                $paragraph[] = $text;
                $kind        = 'text';
            }
        }

        self::flushParagraph($paragraph, $kind, $lines, $links, $width);
        self::flushTable($table, $lines, $links);

        return new MarkdownDocument($lines, $links);
    }

    /**
     * @param list<string> $paragraph
     * @param 'text'|'bullet'|'quote' $kind
     * @param list<Line> $lines
     * @param list<string> $links
     */
    private static function flushParagraph(array &$paragraph, string &$kind, array &$lines, array &$links, int $width): void
    {
        if ($paragraph === []) {
            $kind = 'text';

            return;
        }

        $joined = implode(' ', $paragraph);

        match ($kind) {
            'bullet' => self::wrapInline($lines, $links, $joined, $width, '• ', '  ', self::style('accent')),
            'quote'  => self::wrapInline($lines, $links, $joined, $width, '│ ', '│ ', self::style('dim'), self::style('dim')),
            'text'   => self::wrapInline($lines, $links, $joined, $width, '', ''),
        };

        $paragraph = [];
        $kind      = 'text';
    }

    /**
     * Render a run of buffered `|`-delimited rows as a bordered table. A
     * second row made only of `-`/`:`/`|`/space is GFM's header separator —
     * dropped, and the row before it treated as the header.
     *
     * @param list<string> $table
     * @param list<Line> $lines
     * @param list<string> $links
     */
    private static function flushTable(array &$table, array &$lines, array &$links): void
    {
        if ($table === []) {
            return;
        }

        $rawRows = [];

        foreach ($table as $row) {
            $rawRows[] = self::tableCells($row);
        }

        $headers = [];

        if (isset($table[1]) && self::isSeparatorRow($table[1])) {
            $headers = array_shift($rawRows);
            array_shift($rawRows);
        }

        $headers = self::textifyCells($headers, $links);
        $rows    = [];

        foreach ($rawRows as $row) {
            $rows[] = self::textifyCells($row, $links);
        }

        foreach (self::renderTable($headers, $rows) as $line) {
            $lines[] = $line;
        }

        $table = [];
    }

    /** @return list<string> */
    private static function tableCells(string $row): array
    {
        return array_map(trim(...), explode('|', \trim($row, "| \t")));
    }

    private static function isSeparatorRow(string $row): bool
    {
        return preg_match('/^[\s|:-]+$/', $row) === 1 && str_contains($row, '-');
    }

    /**
     * Cell content still allows inline markup (e.g. a `[text](link)`), but
     * a table cell renders as plain text — styling per cell would need
     * {@see Line} in the grid itself, more than a single README table
     * (D-099) needs; link targets still register and keep their `[N]`
     * marker so they stay followable from a table row.
     *
     * @param list<string> $cells
     * @param list<string> $links
     *
     * @return list<string>
     */
    private static function textifyCells(array $cells, array &$links): array
    {
        $text = [];

        foreach ($cells as $cell) {
            $plain = '';

            foreach (self::inline($cell, $links, Style::none()) as $token) {
                $plain .= $token['text'];
            }

            $text[] = $plain;
        }

        return $text;
    }

    /**
     * @param list<string> $headers
     * @param list<list<string>> $rows
     *
     * @return list<Line>
     */
    private static function renderTable(array $headers, array $rows): array
    {
        $columns = max(1, count($headers), ...array_map(count(...), $rows));
        $widths  = array_fill(0, $columns, 0);

        foreach ([$headers, ...$rows] as $row) {
            foreach (self::padRow($row, $columns) as $index => $cell) {
                $widths[$index] = max($widths[$index], Str::width($cell));
            }
        }

        $lines = [self::tableBorder($widths, '┌', '┬', '┐')];

        if ($headers !== []) {
            $lines[] = self::tableRow(self::padRow($headers, $columns), $widths, true);
            $lines[] = self::tableBorder($widths, '├', '┼', '┤');
        }

        foreach ($rows as $row) {
            $lines[] = self::tableRow(self::padRow($row, $columns), $widths, false);
        }

        $lines[] = self::tableBorder($widths, '└', '┴', '┘');

        return $lines;
    }

    /**
     * @param list<string> $row
     *
     * @return list<string>
     */
    private static function padRow(array $row, int $columns): array
    {
        $out = [];

        for ($i = 0; $i < $columns; ++$i) {
            $out[] = $row[$i] ?? '';
        }

        return $out;
    }

    /** @param array<int, int> $widths */
    private static function tableBorder(array $widths, string $left, string $middle, string $right): Line
    {
        $line = new Line();
        $line->add($left, self::style('dim'));

        $last = count($widths) - 1;

        foreach ($widths as $index => $width) {
            $line->add(str_repeat('─', $width + 2), self::style('dim'));
            $line->add($index === $last ? $right : $middle, self::style('dim'));
        }

        return $line;
    }

    /**
     * @param list<string> $cells
     * @param array<int, int> $widths
     */
    private static function tableRow(array $cells, array $widths, bool $header): Line
    {
        $line = new Line();
        $line->add('│', self::style('dim'));

        foreach ($cells as $index => $cell) {
            $padded = ' ' . Str::padRight($cell, $widths[$index]) . ' ';
            $line->add($padded, $header ? Style::none()->bold() : null);
            $line->add('│', self::style('dim'));
        }

        return $line;
    }

    /**
     * Inline markup (bold/italic/code/links) is parsed against the whole
     * paragraph first, then the resulting styled tokens are word-wrapped —
     * not the other way around — so a span isn't missed just because the
     * word-wrap would otherwise have cut it in two (D-099).
     *
     * @param list<Line> $lines
     * @param list<string> $links
     */
    private static function wrapInline(
        array &$lines,
        array &$links,
        string $text,
        int $width,
        string $firstPrefix,
        string $contPrefix,
        ?Style $prefixStyle = null,
        ?Style $base = null,
    ): void {
        $inner  = max(1, $width - Str::width($firstPrefix));
        $tokens = self::inline($text, $links, $base ?? Style::none());
        $atoms  = self::atomize($tokens);

        foreach (self::packAtoms($atoms, $inner) as $index => $segments) {
            $line = new Line();
            $line->add($index === 0 ? $firstPrefix : $contPrefix, $prefixStyle);

            foreach ($segments as $segment) {
                $line->add($segment['text'], $segment['style']);
            }

            $lines[] = $line;
        }
    }

    /**
     * @param list<string> $links
     *
     * @return list<array{text: string, style: Style}>
     */
    private static function inline(string $text, array &$links, Style $base): array
    {
        $tokens  = [];
        $pattern = '/(\[[^\]]+\]\([^)]+\))|(`[^`]+`)|(\*\*[^*]+\*\*)|(\*[^*]+\*|_[^_]+_)/';
        $offset  = 0;

        if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) !== false) {
            foreach ($matches as $match) {
                $whole = $match[0][0];
                $start = $match[0][1];

                if ($start > $offset) {
                    $tokens[] = ['text' => substr($text, $offset, $start - $offset), 'style' => $base];
                }

                foreach (self::inlineToken($match, $whole, $links, $base) as $token) {
                    $tokens[] = $token;
                }

                $offset = $start + strlen($whole);
            }
        }

        if ($offset < strlen($text)) {
            $tokens[] = ['text' => substr($text, $offset), 'style' => $base];
        }

        return $tokens;
    }

    /**
     * @param array<int, array{string, int}> $match
     * @param list<string> $links
     *
     * @return list<array{text: string, style: Style}>
     */
    private static function inlineToken(array $match, string $whole, array &$links, Style $base): array
    {
        if (($match[1][1] ?? -1) !== -1 && preg_match('/\[([^\]]+)\]\(([^)]+)\)/', $whole, $link) === 1) {
            $links[] = $link[2];

            return [
                ['text' => $link[1], 'style' => self::style('link')],
                ['text' => '[' . count($links) . ']', 'style' => self::style('dim')],
            ];
        }

        if (($match[2][1] ?? -1) !== -1) {
            return [['text' => \trim($whole, '`'), 'style' => self::style('code')]];
        }

        if (($match[3][1] ?? -1) !== -1) {
            return [['text' => \trim($whole, '*'), 'style' => $base->bold()]];
        }

        return [['text' => \trim($whole, '*_'), 'style' => $base->italic()]];
    }

    /**
     * Split styled tokens into word atoms for wrapping. `glue` marks an
     * atom that must render immediately after the previous one with no
     * space — e.g. a link label followed by its `[N]` reference marker.
     *
     * @param list<array{text: string, style: Style}> $tokens
     *
     * @return list<array{text: string, style: Style, glue: bool}>
     */
    private static function atomize(array $tokens): array
    {
        $atoms    = [];
        $glueNext = false;

        foreach ($tokens as $token) {
            $pieces = explode(' ', $token['text']);
            $last   = count($pieces) - 1;

            foreach ($pieces as $index => $piece) {
                if ($piece === '') {
                    $glueNext = false;

                    continue;
                }

                $atoms[]  = ['text' => $piece, 'style' => $token['style'], 'glue' => $glueNext];
                $glueNext = $index === $last;
            }
        }

        return $atoms;
    }

    /**
     * Greedily pack atoms into lines no wider than `$width`, splitting an
     * over-wide atom across lines as a last resort.
     *
     * @param list<array{text: string, style: Style, glue: bool}> $atoms
     *
     * @return list<list<array{text: string, style: Style}>>
     */
    private static function packAtoms(array $atoms, int $width): array
    {
        $lines        = [];
        $current      = [];
        $currentWidth = 0;

        foreach ($atoms as $atom) {
            $atomText  = $atom['text'];
            $atomWidth = Str::width($atomText);
            $withSpace = $current !== [] && ! $atom['glue'];

            if ($current !== [] && $currentWidth + ($withSpace ? 1 : 0) + $atomWidth > $width) {
                $lines[]      = $current;
                $current      = [];
                $currentWidth = 0;
                $withSpace    = false;
            }

            if ($withSpace) {
                $current[] = ['text' => ' ', 'style' => Style::none()];
                $currentWidth += 1;
            }

            while ($atomWidth > $width) {
                if ($current !== []) {
                    $lines[]      = $current;
                    $current      = [];
                    $currentWidth = 0;
                }

                $lines[]  = [['text' => Str::substr($atomText, 0, $width), 'style' => $atom['style']]];
                $atomText = Str::substr($atomText, $width);

                $atomWidth = Str::width($atomText);
            }

            $current[] = ['text' => $atomText, 'style' => $atom['style']];
            $currentWidth += $atomWidth;
        }

        if ($current !== []) {
            $lines[] = $current;
        }

        return $lines === [] ? [[]] : $lines;
    }

    private static function heading(int $level): Style
    {
        return match ($level) {
            1       => Style::none()->withForeground(Theme::accent())->bold(),
            2       => Style::none()->bold(),
            default => Style::none()->underline(),
        };
    }

    private static function style(string $name): Style
    {
        return match ($name) {
            'accent' => Style::none()->withForeground(Theme::accent()),
            'link'   => Style::none()->withForeground(Theme::accent())->underline(),
            'code'   => Style::none()->dim(),
            default  => Style::none()->dim(),
        };
    }
}
