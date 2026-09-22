<?php

declare(strict_types=1);

namespace ArchFixture\Shape\Implementing;

use ArchFixture\Kind\Interfaces\Contract;

final class Concrete implements Contract
{
    /** Satisfies the contract. */
    public function value(): int
    {
        return 7;
    }
}
