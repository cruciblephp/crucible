<?php

declare(strict_types=1);

namespace ArchFixture\Shape\Extending;

use ArchFixture\Kind\Abstracts\Base;

final class Child extends Base
{
    /** Satisfies the abstract parent. */
    public function value(): int
    {
        return 6;
    }
}
