<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Bridge\Laravel;

use function escapeshellarg;
use function exec;
use function is_file;

use const PHP_BINARY;

/**
 * Whether the Laravel oracle (ORACLES.md) can boot on this PHP, asked of Composer's own platform check.
 */
final class LaravelOracle
{
    /** @var array<string, bool> */
    private static array $answers = [];

    public static function runsHere(string $oracle): bool
    {
        $check = $oracle . '/vendor/composer/platform_check.php';

        if (!is_file($check)) {
            return true;
        }

        if (!isset(self::$answers[$check])) {
            // A child: the check throws on refusal, which must not end this process.
            exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($check) . ' 2>&1', $output, $exit);

            self::$answers[$check] = $exit === 0;
        }

        return self::$answers[$check];
    }
}
