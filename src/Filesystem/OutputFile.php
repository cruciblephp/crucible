<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Filesystem;

use function dirname;
use function is_dir;
use function mkdir;

/**
 * A file a run writes because it was asked to: a log, a report, a
 * coverage file (D-148). PHPUnit creates the missing directories of a
 * JUnit, TeamCity, testdox or coverage path, so every such writer here
 * prepares its directory through this one place.
 */
final class OutputFile
{
    /** Whether the directory `$path` goes into exists, creating it, and its parents, if not. */
    public static function prepareDirectory(string $path): bool
    {
        $directory = dirname($path);

        return is_dir($directory) || @mkdir($directory, 0o777, true) || is_dir($directory);
    }
}
