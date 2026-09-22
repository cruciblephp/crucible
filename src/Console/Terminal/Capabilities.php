<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Terminal;

use function getenv;
use function in_array;

/**
 * Detects what the current terminal environment supports.
 *
 * Two questions, deliberately separate. {@see color()} asks whether an SGR
 * sequence will be rendered rather than shown; {@see animation()} asks
 * whether the screen can be *rewritten* — cursor movement, carriage
 * returns, alternate screen.
 *
 * ⚠ They are not the same question and conflating them is how a build log
 * fills with `\r` frames. A CI runner that allocates a PTY answers yes to
 * both by TTY detection alone, while its log viewer records every frame;
 * `TERM=dumb` answers yes to the TTY and understands neither.
 */
final class Capabilities
{
    /**
     * Terminals that are a TTY but cannot render either.
     *
     * `dumb` is what Emacs' shell, some editor consoles, and `TERM` stripped
     * by a service manager report. An empty TERM on a real TTY is the same
     * claim made by omission.
     *
     * @var list<string>
     */
    private const array BLIND = ['', 'dumb', 'unknown'];

    private static ?bool $colorOverride = null;

    private static ?bool $animationOverride = null;

    private function __construct() {}

    /** Force colour support on or off, overriding auto-detection. Null resets. */
    public static function forceColor(?bool $enabled): void
    {
        self::$colorOverride = $enabled;
    }

    /**
     * Force redraw support on or off, overriding auto-detection. Null resets.
     *
     * ⚠ Needed because {@see animation()} reads the environment, and a test
     * that asserts what an interactive terminal is shown would otherwise
     * pass on a laptop and fail in CI — where `CI` is set, which is exactly
     * what the detection is for.
     */
    public static function forceAnimation(?bool $enabled): void
    {
        self::$animationOverride = $enabled;
    }

    /** Drop both overrides, back to detecting the real environment. */
    public static function reset(): void
    {
        self::$colorOverride     = null;
        self::$animationOverride = null;
    }

    public static function color(Terminal $terminal): bool
    {
        if (self::$colorOverride !== null) {
            return self::$colorOverride;
        }

        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('FORCE_COLOR') !== false) {
            return true;
        }

        return !self::isBlindTerminal() && $terminal->supportsInteractivity();
    }

    /**
     * Whether the screen may be redrawn in place: spinners, bars, sweeps.
     *
     * Stricter than {@see color()} on purpose. `--colors=always` or
     * `FORCE_COLOR` in CI is a legitimate ask — the log viewer renders SGR —
     * but no one is watching a frame get overwritten, and every intermediate
     * one is kept forever. So CI is excluded here and not there.
     */
    public static function animation(Terminal $terminal): bool
    {
        if (self::$animationOverride !== null) {
            return self::$animationOverride;
        }

        return !self::isContinuousIntegration()
            && !self::isBlindTerminal()
            && $terminal->supportsInteractivity();
    }

    /**
     * The conventional marker, set by every mainstream runner.
     *
     * GitHub Actions, GitLab CI, CircleCI, Travis, Buildkite, Woodpecker and
     * Jenkins (via its pipeline) all set `CI`; the value is irrelevant, only
     * that something claimed it. `CI=false` is Travis's way of saying no.
     */
    private static function isContinuousIntegration(): bool
    {
        $ci = getenv('CI');

        return !in_array($ci, [false, '', '0', 'false'], true);
    }

    private static function isBlindTerminal(): bool
    {
        $term = getenv('TERM');

        return $term !== false && in_array($term, self::BLIND, true);
    }
}
