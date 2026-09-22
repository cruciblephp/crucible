<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use function extension_loaded;
use function ini_get;
use function sprintf;

/**
 * Whether this process measures time or measures xdebug.
 *
 * Any mode other than off hooks every function call — `develop` and
 * `coverage` both do — so every duration taken here is inflated by a
 * factor nobody can see in the number. The guards read `ini_get`, not
 * `XDEBUG_MODE`: the env var is consulted at startup and the ini value
 * is what the extension actually runs with.
 *
 * ✓ Measured: `-d xdebug.mode=off` makes `ini_get('xdebug.mode')`
 * return `''`, never the string `off` — xdebug spells the off mode as
 * no modes at all. So an empty mode is the clean answer, and it does
 * not distinguish an absent extension from a disabled one.
 *
 * This is the parent process's answer. Workers are spawned with
 * `-d xdebug.mode=off` unless they are collecting coverage
 * (`Supervisor::phpArguments()`), so a parallel run's worker timings
 * are clean while the in-process portion's are not.
 */
final readonly class TimingOverhead
{
    /**
     * The effective xdebug mode. Empty when nothing hooks calls —
     * the extension is absent, or it is loaded with the off mode.
     */
    public static function mode(): string
    {
        if (!extension_loaded('xdebug')) {
            return '';
        }

        $mode = ini_get('xdebug.mode');

        return $mode === false ? '' : $mode;
    }

    /**
     * True when a duration measured under this mode costs more than
     * the work it timed. The mode is a parameter so the decision is
     * provable for every spelling: `xdebug.mode` is PHP_INI_SYSTEM,
     * so a test cannot reach the other branches by setting it.
     */
    public static function inflating(?string $mode = null): bool
    {
        $mode ??= self::mode();

        return $mode !== '' && $mode !== 'off';
    }

    /**
     * One line naming the inflation, for any report that presents a
     * duration as a fact about a test. Null when timings are clean, so
     * a caller can print it unconditionally.
     *
     * @return ?non-empty-string
     */
    public static function notice(?string $mode = null): ?string
    {
        $mode ??= self::mode();

        if (!self::inflating($mode)) {
            return null;
        }

        return sprintf(
            'These durations are inflated: xdebug is loaded in %s mode and hooks every call. '
            . 'Re-run with -d xdebug.mode=off to time the tests instead of the profiler.',
            $mode,
        );
    }
}
