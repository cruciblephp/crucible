<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Terminal;

/**
 * Creates the appropriate {@see Terminal} for the current environment.
 *
 * UnixTerminal unconditionally, matching the source project: its own
 * supportsInteractivity() already degrades safely on a platform with no
 * `stty` (Windows included) — enableRawMode()/read() are only ever reached
 * once that check passes, so this is a safe default everywhere, not just
 * on POSIX. A native Windows backend is future work, not a bug in this one.
 */
final class TerminalFactory
{
    private function __construct() {}

    public static function make(): Terminal
    {
        return new UnixTerminal();
    }
}
