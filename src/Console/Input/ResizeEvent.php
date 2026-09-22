<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Input;

/** The terminal viewport changed size. */
final readonly class ResizeEvent implements Event
{
    public function __construct(
        public int $width,
        public int $height,
    ) {}
}
