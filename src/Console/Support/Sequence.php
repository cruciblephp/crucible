<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Support;

/**
 * The low-level lookup table of ANSI/VT100 escape sequences.
 *
 * Each case's value is the final byte(s) of a Control Sequence Introducer
 * escape. {@see render()} is the single entry point.
 */
enum Sequence: string
{
    case CursorUp     = 'A';
    case CursorDown   = 'B';
    case CursorRight  = 'C';
    case CursorLeft   = 'D';
    case CursorColumn = 'G';
    case EraseDown    = 'J';
    case EraseLine    = '2K';
    case EraseScreen  = '2J';
    case CursorHome   = 'H';
    case HideCursor   = '?25l';
    case ShowCursor   = '?25h';
    case AltScreenOn  = '?1049h';
    case AltScreenOff = '?1049l';

    private const CSI = "\e[";

    /**
     * Render the escape sequence. Cursor-movement cases embed $count; a count
     * of zero yields an empty string so callers can move "up 0" as a no-op.
     */
    public function render(int $count = 1): string
    {
        return match ($this) {
            self::CursorUp, self::CursorDown, self::CursorRight, self::CursorLeft, self::CursorColumn
                    => $count > 0 ? self::CSI . $count . $this->value : '',
            default => self::CSI . $this->value,
        };
    }
}
