<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact\Fixtures;

/**
 * Impact-graph fixture: the leaf nothing here depends on.
 */
final readonly class Alpha
{
    public function value(): int
    {
        return 1;
    }
}
