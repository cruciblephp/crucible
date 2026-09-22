<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use ArrayAccess;
use LucianoPereira\Crucible\Assert\Exporter;
use Override;

use function array_key_exists;
use function is_array;

final class ArrayHasKey extends Constraint
{
    public function __construct(
        private readonly int|string $key,
    ) {}

    #[Override]
    public function matches(mixed $other): bool
    {
        if (is_array($other)) {
            return array_key_exists($this->key, $other);
        }

        if ($other instanceof ArrayAccess) {
            return $other->offsetExists($this->key);
        }

        return false;
    }

    public function toString(): string
    {
        return 'has the key ' . Exporter::export($this->key);
    }
}
