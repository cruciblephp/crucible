<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Interfaces;

interface Contract
{
    /** The one method the implementing fixtures satisfy. */
    public function value(): int;
}
