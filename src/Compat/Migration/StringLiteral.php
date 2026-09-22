<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use function str_replace;
use function substr;

final class StringLiteral
{
    /**
     * A plain (non-interpolated) string literal's value — good enough
     * for the simple ASCII path/filename/class-name literals this
     * feature targets, not a general PHP string-literal parser.
     */
    public static function unquote(string $literal): string
    {
        $quote = $literal[0];
        $inner = substr($literal, 1, -1);

        return str_replace(['\\\\', '\\' . $quote], ['\\', $quote], $inner);
    }
}
