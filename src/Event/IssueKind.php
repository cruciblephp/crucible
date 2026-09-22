<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

use const E_DEPRECATED;
use const E_NOTICE;
use const E_USER_DEPRECATED;
use const E_USER_NOTICE;
use const E_USER_WARNING;
use const E_WARNING;

/**
 * The kinds of PHP-level issue a test can trigger without failing:
 * the spec's "OK, but there were issues!" categories.
 */
enum IssueKind: string
{
    case Deprecation = 'deprecation';
    case Notice      = 'notice';
    case Warning     = 'warning';

    public static function fromErrorLevel(int $level): ?self
    {
        return match ($level) {
            E_DEPRECATED, E_USER_DEPRECATED => self::Deprecation,
            E_NOTICE, E_USER_NOTICE         => self::Notice,
            E_WARNING, E_USER_WARNING       => self::Warning,
            default                         => null,
        };
    }
}
