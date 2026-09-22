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
 * Impact-graph fixture: an island — no edges in, no edges out.
 *
 * @phpcpd-keep Referenced by class-string in the impact-graph tests only.
 */
final readonly class Delta
{
    public function value(): int
    {
        return 4;
    }
}
