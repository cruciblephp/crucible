<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Input;

use function in_array;
use function is_array;
use function preg_match;

/**
 * Named terminal key sequences and helpers for matching raw input against them.
 *
 * Many keys have several byte representations depending on terminal mode
 * (e.g. application vs. normal cursor keys), so the movement helpers return a
 * list of every sequence that should be treated as that logical key.
 */
final class Key
{
    public const string UP          = "\e[A";
    public const string UP_ARROW    = "\eOA";
    public const string DOWN        = "\e[B";
    public const string DOWN_ARROW  = "\eOB";
    public const string RIGHT       = "\e[C";
    public const string RIGHT_ARROW = "\eOC";
    public const string LEFT        = "\e[D";
    public const string LEFT_ARROW  = "\eOD";

    public const string ENTER     = "\n";
    public const string RETURN    = "\r";
    public const string SPACE     = ' ';
    public const string TAB       = "\t";
    public const string SHIFT_TAB = "\e[Z";
    public const string BACKSPACE = "\x7f";
    public const string DELETE    = "\e[3~";
    public const string ESCAPE    = "\e";

    public const array HOME = ["\e[1~", "\e[H", "\eOH", "\e[7~"];
    public const array END  = ["\e[4~", "\e[F", "\eOF", "\e[8~"];

    public const string CTRL_A = "\x01";
    public const string CTRL_B = "\x02";
    public const string CTRL_C = "\x03";
    public const string CTRL_D = "\x04";
    public const string CTRL_E = "\x05";
    public const string CTRL_F = "\x06";
    public const string CTRL_H = "\x08";
    public const string CTRL_N = "\x0e";
    public const string CTRL_P = "\x10";
    public const string CTRL_U = "\x15";

    private function __construct() {}

    /** @return list<string> */
    public static function up(): array
    {
        return [self::UP, self::UP_ARROW, self::CTRL_P];
    }

    /** @return list<string> */
    public static function down(): array
    {
        return [self::DOWN, self::DOWN_ARROW, self::CTRL_N];
    }

    /** @return list<string> */
    public static function left(): array
    {
        return [self::LEFT, self::LEFT_ARROW, self::CTRL_B];
    }

    /** @return list<string> */
    public static function right(): array
    {
        return [self::RIGHT, self::RIGHT_ARROW, self::CTRL_F];
    }

    /** @return list<string> */
    public static function enter(): array
    {
        return [self::ENTER, self::RETURN];
    }

    /**
     * Determine whether a raw key matches any of the given sequences.
     *
     * @param string|list<string> $expected
     */
    public static function is(string $key, string|array $expected): bool
    {
        $expected = is_array($expected) ? $expected : [$expected];

        return in_array($key, $expected, true);
    }

    /**
     * Determine whether a key is a single printable character (not a control
     * or escape sequence) that can be inserted into a text buffer.
     */
    public static function isPrintable(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        // Reject anything containing control characters or escape sequences.
        return preg_match('/[\x00-\x1f\x7f]/', $key) !== 1;
    }
}
