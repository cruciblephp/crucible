<?php

declare(strict_types=1);

namespace ArchFixture\Kind\Invokable;

final class Doubler
{
    /** The single method that makes this target invokable. */
    public function __invoke(int $n): int
    {
        return $n * 2;
    }
}
