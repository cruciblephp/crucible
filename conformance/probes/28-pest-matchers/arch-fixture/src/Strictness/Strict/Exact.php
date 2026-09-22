<?php

declare(strict_types=1);

namespace ArchFixture\Strictness\Strict;

final class Exact
{
    /** Compares with `===`, under `declare(strict_types=1)`. */
    public function isTen(int $n): bool
    {
        return $n === 10;
    }
}
