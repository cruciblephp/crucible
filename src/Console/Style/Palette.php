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
 * The colour palette prompts render with.
 *
 * `Full` (the default) emits colours exactly as given — 16-colour, 256, or
 * 24-bit RGB. `Classic16` snaps every colour to the nearest of the classic 16
 * ANSI colours, for a retro look or limited terminals.
 */
enum Palette
{
    case Full;
    case Classic16;
}
