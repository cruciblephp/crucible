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
 * Impact-graph fixture: references one vendored and one owned class,
 * identically, so only the vendor/ segment can tell them apart.
 *
 * @phpcpd-keep Referenced by class-string in the impact-graph tests only.
 */
final readonly class Consumer
{
    public function total(): int
    {
        return (new Vendored())->value() + (new Owned())->value();
    }
}
