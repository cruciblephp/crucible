<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Fixtures\InlineInvalid;

use LucianoPereira\Crucible\Attributes\Check;
use RuntimeException;

/** @phpcpd-keep Negative fixture: proves the both-returns-and-throws load error. */
final class ConflictingClaims
{
    #[Check([1], returns: 1, throws: RuntimeException::class)]
    public static function torn(int $value): int
    {
        return $value;
    }
}
