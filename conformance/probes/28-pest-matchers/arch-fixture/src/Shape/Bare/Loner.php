<?php

declare(strict_types=1);

namespace ArchFixture\Shape\Bare;

/** Extends nothing, implements nothing, and has neither ctor nor dtor. */
final class Loner
{
    /** The only member. */
    public function value(): int
    {
        return 4;
    }
}
