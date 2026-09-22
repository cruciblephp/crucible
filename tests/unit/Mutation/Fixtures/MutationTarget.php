<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Mutation\Fixtures;

/**
 * The "original" a MutantApplier test mutates. Only ever loaded inside a
 * forked child (through the applier's autoloader, which serves the mutant
 * in its place), so the parent test process stays clean and warmable.
 */
final class MutationTarget
{
    public static function answer(): int
    {
        return 2;
    }
}
