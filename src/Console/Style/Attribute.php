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
 * A text rendition attribute. The case value is its SGR "on" parameter, so
 * attributes render to ANSI directly with no lookup table.
 */
enum Attribute: int
{
    case Bold          = 1;
    case Dim           = 2;
    case Italic        = 3;
    case Underline     = 4;
    case Inverse       = 7;
    case Hidden        = 8;
    case Strikethrough = 9;

    /** The bit this attribute occupies in a {@see Style} attribute mask. */
    public function bit(): int
    {
        return 1 << $this->value;
    }
}
