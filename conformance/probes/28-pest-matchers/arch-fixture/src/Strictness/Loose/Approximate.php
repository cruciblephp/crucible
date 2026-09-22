<?php

namespace ArchFixture\Strictness\Loose;

final class Approximate
{
    /** Compares with `==`, and the file declares no strict types. */
    public function isTen(int $n): bool
    {
        return $n == 10;
    }
}
