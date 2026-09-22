<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Dialect\Fixtures\Inline;

use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\Check;
use RuntimeException;

/**
 * Every claim in this fixture is wrong on purpose: it builds cleanly,
 * and each test fails when run — the failure-path half of the
 * inline-dialect suite.
 *
 * @phpcpd-keep Inline-dialect fixture: discovered by scan, never referenced.
 * @crucible expect(true)->toBeFalse()
 */
final class WrongClaim
{
    #[Check([1], returns: 2)]
    public static function identity(int $value): int
    {
        return $value;
    }

    #[Check(throws: RuntimeException::class)]
    public static function calm(): bool
    {
        return true;
    }

    #[Check]
    public static function explode(): never
    {
        throw new InvalidArgumentException('nobody claimed this one');
    }
}
