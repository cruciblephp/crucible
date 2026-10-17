<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Tests\Mutation\Fixtures;

/**
 * Lines a coverage driver never reports, beside one it reports unrun.
 * Only ever tokenized by CoveringTestsTest.
 */
#[Marker(weight: 3)]
final class UnreportedLines
{
    public int $sides = 4 + 1;

    #[Marker(weight: -1000)]
    public function handle(int $x): int
    {
        return $x + 1;
    }

    /** @return array<string, string> */
    public function casts(): array
    {
        return [
            'id'      => 'int',
            'headers' => 'array',
        ];
    }

    public function unused(int $x): int
    {
        return $x - 1;
    }
}
