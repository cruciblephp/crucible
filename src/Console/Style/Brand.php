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
 * The project's own two colours, and the neutral they sit against.
 *
 * ✓ Taken from `assets/crucible.svg`, which carries exactly two fills —
 * `#e80c08` and `#f17510` — and nothing else. They are named here rather
 * than repeated at each call site so a rebrand is one edit, and so a
 * third colour has to be added on purpose rather than by someone
 * reaching for `Color::red()` because it was nearer.
 *
 * `slate` is NOT a brand colour. It is the unlit state behind an
 * animation, which needs to read as "off" against both colours without
 * competing with either.
 *
 * Every one degrades: {@see Color::forPalette()} snaps RGB to the
 * classic 16 where the terminal cannot do better, so nothing here
 * assumes truecolor.
 */
final class Brand
{
    private function __construct() {}

    /** The primary: the hot centre of the mark. */
    public static function ember(): Color
    {
        return Color::rgb(0xE8, 0x0C, 0x08);
    }

    /** The secondary: one step out from the centre. */
    public static function flame(): Color
    {
        return Color::rgb(0xF1, 0x75, 0x10);
    }

    /**
     * Unlit. Not a brand colour — see the class docblock.
     *
     * A specific RGB rather than {@see Color::gray()}, which is palette
     * index 8 and therefore whatever the terminal's theme makes of it.
     * An animation's "off" state has to sit at a known distance from both
     * brand colours, so this one is pinned; on a Classic16 terminal
     * forPalette() snaps it to that same index anyway.
     */
    public static function slate(): Color
    {
        return Color::rgb(0x4A, 0x4A, 0x4A);
    }
}
