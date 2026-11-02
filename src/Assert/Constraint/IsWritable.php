<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Assert\Constraint;

use Override;

use function is_string;
use function is_writable;
use function sprintf;

final class IsWritable extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && is_writable($other);
    }

    public function toString(): string
    {
        return 'is writable';
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return sprintf('Failed asserting that "%s" %s.', is_string($other) ? $other : '', $this->toString());
    }
}
