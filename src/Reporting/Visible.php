<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Reporting;

use function ord;
use function preg_replace_callback;
use function sprintf;

/**
 * Makes control characters and ANSI escape sequences visible in a
 * human-facing name, as PHPUnit 13.4.8 does: a data set named
 * "\e[31mred" prints as `\u{001B}[31mred`, not as a terminal escape.
 *
 * A test name and a data set name come from the suite, not from the
 * reporter; a stray escape can recolor or corrupt the output, and in a
 * logfile it is invisible. The JSON event stream carries the raw string
 * and escapes it as JSON; this is for the text a person reads.
 */
final readonly class Visible
{
    /**
     * @param non-empty-string $text
     *
     * @return non-empty-string
     */
    public static function text(string $text): string
    {
        $visible = (string) preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn(array $match): string => sprintf('\\u{%04X}', ord($match[0])),
            $text,
        );

        return $visible === '' ? $text : $visible;
    }
}
