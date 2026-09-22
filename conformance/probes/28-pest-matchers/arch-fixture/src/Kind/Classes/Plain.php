<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Classes;

final class Plain
{
    /** A method, so the target is not empty of behaviour. */
    public function value(): int
    {
        return 1;
    }
}
