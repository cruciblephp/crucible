<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Support;

use function array_merge;
use function count;
use function explode;
use function intdiv;
use function max;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;
use function preg_replace;
use function str_repeat;
use function strlen;

/**
 * Multibyte-aware string helpers tuned for terminal rendering.
 *
 * "Width" throughout this class refers to the number of visible columns a
 * string occupies once ANSI escape sequences are removed.
 */
final class Str
{
    private const string ANSI_PATTERN = '/\e\[[0-9;?]*[ -\/]*[@-~]/';

    /**
     * Distinct short strings and graphemes whose width is remembered.
     *
     * ⚠ Bounded rather than unbounded: width is a pure function of its
     * input, so a stale entry is impossible, but a run that measured a
     * million distinct strings would otherwise keep every one of them.
     */
    private const int MEMO = 1024;

    /** Bytes a string may have and still be worth remembering whole. */
    private const int MEMO_BYTES = 8;

    /** @var array<string, int> */
    private static array $widths = [];

    /** @var array<string, int> */
    private static array $charWidths = [];

    private function __construct() {}

    public static function length(string $value): int
    {
        return mb_strlen($value);
    }

    /** Strip ANSI escape sequences from a string. */
    public static function stripAnsi(string $value): string
    {
        return (string) preg_replace(self::ANSI_PATTERN, '', $value);
    }

    /**
     * The visible column width of a string, accounting for wide characters.
     *
     * ⚠ Memoized because a frame asks this once per cell: an 80x20 map
     * is 1600 calls, each of which otherwise runs a `preg_replace` over
     * the ANSI pattern and splits a one-character string.
     */
    public static function width(string $value): int
    {
        if (isset(self::$widths[$value])) {
            return self::$widths[$value];
        }

        $width = 0;

        foreach (self::graphemes(self::stripAnsi($value)) as $grapheme) {
            $width += self::charWidth($grapheme);
        }

        // Only the short ones: a cell, a glyph, a padded number. A full
        // line is measured once and never asked for again.
        if (strlen($value) <= self::MEMO_BYTES && count(self::$widths) < self::MEMO) {
            self::$widths[$value] = $width;
        }

        return $width;
    }

    /** @return list<string> */
    public static function graphemes(string $value): array
    {
        return mb_str_split($value);
    }

    /**
     * The number of terminal columns a single grapheme occupies: 0 for
     * combining/zero-width marks, 2 for East-Asian-wide and emoji, else 1.
     */
    public static function charWidth(string $grapheme): int
    {
        if ($grapheme === '') {
            return 0;
        }

        if (isset(self::$charWidths[$grapheme])) {
            return self::$charWidths[$grapheme];
        }

        $code = mb_ord($grapheme);

        if ($code === false || self::isZeroWidth($code)) {
            $width = 0;
        } else {
            $width = self::isWide($code) ? 2 : 1;
        }

        if (count(self::$charWidths) < self::MEMO) {
            self::$charWidths[$grapheme] = $width;
        }

        return $width;
    }

    private static function isZeroWidth(int $code): bool
    {
        return $code === 0x200D // zero-width joiner
            || ($code >= 0x0300 && $code <= 0x036F) // combining diacritics
            || ($code >= 0x200B && $code <= 0x200F) // zero-width spaces/marks
            || ($code >= 0xFE00 && $code <= 0xFE0F); // variation selectors
    }

    private static function isWide(int $code): bool
    {
        return ($code >= 0x1100 && $code <= 0x115F)   // Hangul Jamo
            || ($code >= 0x2E80 && $code <= 0x303E)   // CJK radicals, Kangxi
            || ($code >= 0x3041 && $code <= 0x33FF)   // Hiragana .. CJK symbols
            || ($code >= 0x3400 && $code <= 0x4DBF)   // CJK Ext A
            || ($code >= 0x4E00 && $code <= 0x9FFF)   // CJK Unified
            || ($code >= 0xA000 && $code <= 0xA4CF)   // Yi
            || ($code >= 0xAC00 && $code <= 0xD7A3)   // Hangul syllables
            || ($code >= 0xF900 && $code <= 0xFAFF)   // CJK compatibility
            || ($code >= 0xFE30 && $code <= 0xFE4F)   // CJK compatibility forms
            || ($code >= 0xFF00 && $code <= 0xFF60)   // fullwidth forms
            || ($code >= 0xFFE0 && $code <= 0xFFE6)   // fullwidth signs
            || ($code >= 0x1F300 && $code <= 0x1FAFF) // emoji & symbols
            || ($code >= 0x20000 && $code <= 0x3FFFD); // CJK Ext B+
    }

    public static function substr(string $value, int $start, ?int $length = null): string
    {
        return mb_substr($value, $start, $length);
    }

    /**
     * Truncate a string to a maximum visible width, appending an ellipsis
     * when the string is longer than the limit.
     */
    public static function truncate(string $value, int $width, string $ellipsis = '…'): string
    {
        if ($width <= 0) {
            return '';
        }

        if (self::width($value) <= $width) {
            return $value;
        }

        $keep = max(0, $width - self::width($ellipsis));

        return self::substr($value, 0, $keep) . $ellipsis;
    }

    /** Pad a string on the right with spaces up to a visible width. */
    public static function padRight(string $value, int $width): string
    {
        $pad = $width - self::width($value);

        return $pad > 0 ? $value . str_repeat(' ', $pad) : $value;
    }

    /** Pad a string on the left with spaces up to a visible width. */
    public static function padLeft(string $value, int $width): string
    {
        $pad = $width - self::width($value);

        return $pad > 0 ? str_repeat(' ', $pad) . $value : $value;
    }

    /** Centre a string within a visible width. */
    public static function padBoth(string $value, int $width): string
    {
        $pad = $width - self::width($value);

        if ($pad <= 0) {
            return $value;
        }

        $left = intdiv($pad, 2);

        return str_repeat(' ', $left) . $value . str_repeat(' ', $pad - $left);
    }

    /**
     * Wrap text to a given width, breaking on word boundaries where possible
     * and hard-breaking words that exceed the width.
     *
     * @return list<string>
     */
    public static function wrap(string $value, int $width): array
    {
        if ($width <= 0) {
            return [$value];
        }

        $lines = [];

        foreach (explode("\n", $value) as $paragraph) {
            $lines = array_merge($lines, self::wrapParagraph($paragraph, $width));
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private static function wrapParagraph(string $paragraph, int $width): array
    {
        if ($paragraph === '') {
            return [''];
        }

        $lines   = [];
        $current = '';

        foreach (explode(' ', $paragraph) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if (self::width($candidate) <= $width) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            while (self::width($word) > $width) {
                $lines[] = self::substr($word, 0, $width);
                $word    = self::substr($word, $width);
            }

            $current = $word;
        }

        $lines[] = $current;

        return $lines;
    }
}
