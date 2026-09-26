<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use const PHP_OS_FAMILY;

/**
 * The platform's null device, for a child process's descriptor that must be
 * opened and discarded: `/dev/null`, and `NUL` on Windows, where no
 * `/dev/null` exists and proc_open() fails to start the child at all.
 */
final class NullDevice
{
    /** @return non-empty-string */
    public static function path(): string
    {
        return PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    }
}
