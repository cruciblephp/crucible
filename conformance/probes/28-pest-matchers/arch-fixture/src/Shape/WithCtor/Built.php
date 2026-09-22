<?php

declare(strict_types=1);

namespace ArchFixture\Shape\WithCtor;

final class Built
{
    private int $seed;

    /** The constructor this target exists to have. */
    public function __construct()
    {
        $this->seed = 5;
    }

    /** Reads the property so it is not dead. */
    public function value(): int
    {
        return $this->seed;
    }
}
