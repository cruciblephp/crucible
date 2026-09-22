<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Immutable;

final readonly class Point
{
    /** Promoted, so `toHavePropertiesDocumented` sees no own property here. */
    public function __construct(public int $x) {}
}
