<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Fixtures\MockeryShapes;

/*
 * The shapes a mockery mock NAME can arrive in. APPEND ONLY.
 *
 * declareEmptyClass() is the one generator whose input is a name rather
 * than a reflected type, so its corpus is names: already a class,
 * already an interface, already a trait, or nothing at all.
 */

interface ShapeInterface
{
    public function shape(): string;
}

trait ShapeTrait
{
    public function shape(): string
    {
        return 'real';
    }
}

class ShapeClass
{
    public function shape(): string
    {
        return 'class';
    }
}
