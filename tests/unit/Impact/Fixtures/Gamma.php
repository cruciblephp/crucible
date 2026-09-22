<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Impact\Fixtures;

use LucianoPereira\Crucible\Tests\Impact\Fixtures\Beta as Doubler;

/**
 * Impact-graph fixture: the root of the chain, standing in for a test
 * file. References Beta through an aliased import — the alias
 * resolution path.
 *
 * @phpcpd-keep Referenced by class-string in the impact-graph tests only.
 */
final readonly class Gamma
{
    public function quadrupled(): int
    {
        return (new Doubler())->doubled() * 2;
    }
}
