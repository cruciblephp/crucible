<?php

declare(strict_types=1);

namespace ArchFixture\Deps\Crossing;

use ArchFixture\Deps\Used\Target;

/** Reaches out of its own namespace, which is what a crossing is. */
final class Gamma
{
    /** The crossing reference. */
    public function target(): Target
    {
        return new Target();
    }
}
