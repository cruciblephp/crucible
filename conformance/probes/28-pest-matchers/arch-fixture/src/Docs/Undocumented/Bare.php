<?php

declare(strict_types=1);

namespace ArchFixture\Docs\Undocumented;

final class Bare
{
    private int $seed = 9;

    public function value(): int
    {
        return $this->seed;
    }
}
