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
 * Impact-graph fixture: the middle of the Alpha ← Beta ← Gamma chain.
 * References Alpha without an import — the same-namespace resolution
 * path.
 */
final readonly class Beta
{
    public function doubled(): int
    {
        return (new Alpha())->value() * 2;
    }
}
