<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Traits;

trait Helper
{
    /** Composed by nothing: a trait target is judged on its own. */
    public function value(): int
    {
        return 3;
    }
}
