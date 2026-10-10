<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Event;

enum OutputChannel: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
}
