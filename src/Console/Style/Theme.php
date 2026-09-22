<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Style;

/**
 * Global visual theme. Currently the single accent colour used for active
 * highlights, pointers and gutters — change it once to rebrand every prompt.
 */
final class Theme
{
    private static ?Color $accent = null;

    private static ?Palette $palette = null;

    private function __construct() {}

    /** Set the accent colour used across prompts. */
    public static function setAccent(Color $color): void
    {
        self::$accent = $color;
    }

    /**
     * The brand secondary — warm, red-adjacent, but far enough from the
     * failure-red used for FAILED/errored output
     * ({@see \LucianoPereira\Crucible\Reporting\Style::red()}) that a
     * prompt border or spinner never reads as "something went wrong."
     *
     * ⚠ It reads the value from {@see Brand} rather than repeating it.
     * This method held a literal #F17510 and Brand::flame() was added
     * beside it holding the same one — two copies of a brand colour, made
     * while removing 75 copies of a type annotation. One source, or a
     * rebrand changes half the output.
     */
    public static function accent(): Color
    {
        return self::$accent ??= Brand::flame();
    }

    /** Select the colour palette (Full by default, or Classic16). */
    public static function setPalette(Palette $palette): void
    {
        self::$palette = $palette;
    }

    public static function palette(): Palette
    {
        return self::$palette ??= Palette::Full;
    }

    /** Restore the default theme. */
    public static function reset(): void
    {
        self::$accent  = null;
        self::$palette = null;
    }
}
