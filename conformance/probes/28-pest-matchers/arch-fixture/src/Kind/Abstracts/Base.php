<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Abstracts;

abstract class Base
{
    /** Abstract by design: the only member of an abstract-only target. */
    abstract public function value(): int;
}
