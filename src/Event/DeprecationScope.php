<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * Where a deprecation came from — the attribution axis of Symfony's
 * bridge grammar, kept as its three scopes:
 *
 *  - Self: triggered inside the project's own code (source or tests).
 *  - Direct: triggered in a dependency, called straight from the
 *    project's code — an API this project must migrate off itself.
 *  - Indirect: dependency-to-dependency — actionable only upstream.
 */
enum DeprecationScope: string
{
    case Self_    = 'self';
    case Direct   = 'direct';
    case Indirect = 'indirect';
}
