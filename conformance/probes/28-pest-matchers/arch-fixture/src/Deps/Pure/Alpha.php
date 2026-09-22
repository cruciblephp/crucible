<?php

declare(strict_types=1);

namespace ArchFixture\Deps\Pure;

/** References only its sibling, which is what makes this target "pure". */
final class Alpha
{
    /** The sibling reference the boundary rule must not count. */
    public function beta(): Beta
    {
        return new Beta();
    }
}
