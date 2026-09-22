<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

/**
 * Replaces the string-typed executionOrder attribute of phpunit.xml
 * with a native backed enum: invalid values are impossible, not a
 * runtime validation error.
 */
enum ExecutionOrder: string
{
    case Declared      = 'default';
    case DefectsFirst  = 'defects';
    case Duration      = 'duration';
    case Random        = 'random';
    case Reversed      = 'reverse';
    case SizeAscending = 'size';
}
