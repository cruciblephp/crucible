<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Filesystem;

use function chmod;
use function dirname;
use function file_put_contents;
use function fileperms;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function unlink;

/**
 * Write-through-then-rename, in one place.
 *
 * Every file Crucible rewrites in place is state a later run reads
 * back: the result cache, the property-failure database, snapshot
 * files, inline-snapshot source edits, the coverage maps and the
 * dependency index. A plain file_put_contents() truncates the old file
 * before it writes the new one, so Ctrl-C, a full disk or a crashed
 * worker can leave half a file where a complete one used to be — and a
 * corrupt cache is silently treated as empty, while a truncated test
 * source is lost work.
 *
 * The replacement is written beside the target and renamed over it, an
 * operation the filesystem performs atomically on one volume. The
 * temporary name is stable and the callers are single writers (D-021),
 * so no second process can be holding the same partial.
 */
final readonly class AtomicFile
{
    /**
     * @return bool whether the replacement landed
     */
    public static function write(string $file, string $contents): bool
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            return false;
        }

        $partial = $file . '.partial';

        // A rewritten test source must keep its mode; rename() would
        // otherwise install the temporary file's 0600.
        $perms = is_file($file) ? @fileperms($file) : false;
        $mode  = $perms === false ? null : $perms & 0o777;

        if (@file_put_contents($partial, $contents) === false) {
            @unlink($partial);

            return false;
        }

        if ($mode !== null) {
            @chmod($partial, $mode);
        }

        if (!@rename($partial, $file)) {
            @unlink($partial);

            return false;
        }

        return true;
    }
}
