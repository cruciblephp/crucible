<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert;

use InvalidArgumentException;

use function class_exists;
use function enum_exists;
use function interface_exists;
use function sprintf;

/**
 * ✓ PHPUnit 13.4.3 errors, rather than fails, an instanceof assertion
 * naming a type that does not exist: no value could pass it.
 */
final class UnknownClassOrInterfaceException extends InvalidArgumentException
{
    public static function refuse(string $name): void
    {
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            throw new self(sprintf('Class or interface "%s" does not exist', $name));
        }
    }
}
