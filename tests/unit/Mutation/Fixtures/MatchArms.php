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
 * A `match (true)` whose arm lines a coverage driver reports only for the
 * call that matched them. Only ever tokenized by CoveringTestsTest.
 */
final class MatchArms
{
    public function column(?int $precision, ?int $scale): string
    {
        return match (true) {
            $precision === null && $scale === null => 'number',
            $precision === null                    => "number(*,{$scale})",
            default                                => "number({$precision})",
        };
    }

    public function after(int $x): int
    {
        return $x + 1;
    }
}
