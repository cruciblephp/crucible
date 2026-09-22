<?php

declare(strict_types=1);

namespace ArchFixture\Shape\WithDtor;

final class Closed
{
    /** The destructor this target exists to have. */
    public function __destruct() {}
}
