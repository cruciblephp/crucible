<?php

declare(strict_types=1);

namespace ArchFixture\Docs\Documented;

final class Annotated
{
    /** An own property, documented. */
    private int $seed = 8;

    /** An own method, documented. */
    public function value(): int
    {
        return $this->seed;
    }
}
