<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use LucianoPereira\Crucible\Assert\PhpUnitExporter;
use Override;

use function array_is_list;
use function is_array;

final class IsList extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_array($other) && array_is_list($other);
    }

    public function toString(): string
    {
        return 'is a list';
    }

    /** ✓ PHPUnit 13.4.3 names the kind: `an array is a list`, `a string is a list`. */
    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return 'Failed asserting that ' . PhpUnitExporter::noun($other) . ' ' . $this->toString() . '.';
    }
}
