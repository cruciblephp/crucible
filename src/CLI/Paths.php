<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

/**
 * Path resolution shared by every CLI command that accepts a
 * user-given path relative to the working directory.
 *
 * @phpcpd-keep Crucible itself resolves through {@see WorkingDirectory} now.
 * This stays as the published entry point a crucible.php may already call,
 * which is the same reason the duplication extension's own user-facing
 * classes carry the tag: no caller inside src/ is expected, ever.
 */
final class Paths
{
    /**
     * Delegates: WorkingDirectory carries the same resolution, and two
     * copies of it would drift. Kept as the entry point a crucible.php
     * may already be calling.
     *
     * @return non-empty-string
     */
    public static function absolute(string $path, WorkingDirectory $workingDirectory): string
    {
        return $workingDirectory->absolute($path);
    }
}
