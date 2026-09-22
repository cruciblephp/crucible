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
 * The control's other half: same namespace, same depth, no vendor/
 * segment — so it must be tracked where Vendored is not.
 *
 * @phpcpd-keep Referenced by class-string in the impact-graph tests only.
 */
final readonly class Owned
{
    public function value(): int
    {
        return 2;
    }
}
