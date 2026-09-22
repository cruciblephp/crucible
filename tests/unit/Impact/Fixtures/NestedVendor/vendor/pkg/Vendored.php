<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace NestedVendorFixture;

/**
 * Impact-graph fixture: resolvable, but under a NESTED vendor/ segment.
 *
 * @phpcpd-keep Referenced by class-string in the impact-graph tests only.
 */
final readonly class Vendored
{
    public function value(): int
    {
        return 1;
    }
}
