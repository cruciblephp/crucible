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

use function is_file;
use function is_string;
use function sprintf;

final class FileExists extends Constraint
{
    #[Override]
    public function matches(mixed $other): bool
    {
        return is_string($other) && is_file($other);
    }

    public function toString(): string
    {
        return 'exists';
    }

    #[Override]
    protected function failureDescription(mixed $other): string
    {
        return sprintf('Failed asserting that file "%s" %s.', is_string($other) ? $other : '', $this->toString());
    }
}
