<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert;

use Generator;
use InvalidArgumentException;

use function sprintf;

/**
 * ✓ PHPUnit 13.4.3 errors a count of a generator: counting it would
 * consume it.
 */
final class GeneratorNotSupportedException extends InvalidArgumentException
{
    public static function refuse(mixed $value, string $parameter): void
    {
        if ($value instanceof Generator) {
            throw new self(sprintf('Passing an argument of type Generator for the $%s parameter is not supported', $parameter));
        }
    }
}
