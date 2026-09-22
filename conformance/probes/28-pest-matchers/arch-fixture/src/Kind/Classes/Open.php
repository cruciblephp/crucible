<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Classes;

class Open
{
    /** Not final, so `toBeFinal` has a failing member in this target. */
    public function value(): int
    {
        return 2;
    }
}
