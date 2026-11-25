<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Test\TestId;

use function getcwd;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The `path:line` an editor, a terminal and an agent can open directly.
 *
 * A failure's trace starts where the assertion was built, which sits in
 * the assertion library, not in the test: the useful frame is the first
 * one in the test file itself. An error is different — its first frame
 * is where it was thrown, and that is the line worth naming even when
 * the throw lives in the source under test.
 */
final class FailureLocation
{
    public static function of(Failure $failure, TestId $test, Outcome $outcome, ?string $root = null): ?string
    {
        if ($failure->trace === []) {
            return null;
        }

        if ($root === null) {
            $cwd  = getcwd();
            $root = $cwd === false ? null : $cwd;
        }

        if ($outcome === Outcome::Failed) {
            foreach ($failure->trace as $frame) {
                if (self::sameFile($frame->file, $test->file, $root)) {
                    return self::display($frame->file, $frame->line, $root);
                }
            }
        }

        // Errored (and any other outcome carrying a failure): the first
        // frame is the throw site, which is the line the exception names.
        $first = $failure->trace[0];

        return self::display($first->file, $first->line, $root);
    }

    private static function sameFile(string $frame, string $test, ?string $root): bool
    {
        $frame = self::normalise($frame);
        $test  = self::normalise($test);
        $base  = rtrim(self::normalise($root ?? ''), '/');

        if (!WorkingDirectory::isAbsoluteAnyPlatform($frame)) {
            $frame = $base . '/' . $frame;
        }

        if (!WorkingDirectory::isAbsoluteAnyPlatform($test)) {
            $test = $base . '/' . $test;
        }

        return $frame === $test;
    }

    /**
     * Project-relative when the file lives under the project, absolute
     * otherwise, as the test name already is.
     */
    private static function display(string $file, int $line, ?string $root): string
    {
        $file = self::normalise($file);

        if ($root !== null) {
            $prefix = rtrim(self::normalise($root), '/') . '/';

            if (str_starts_with($file, $prefix)) {
                $file = substr($file, strlen($prefix));
            }
        }

        return $file . ':' . $line;
    }

    private static function normalise(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
