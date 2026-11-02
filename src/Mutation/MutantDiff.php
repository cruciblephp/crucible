<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Mutation;

use function array_slice;
use function count;
use function explode;
use function min;
use function trim;

/**
 * What a mutant changed, as the original lines and the lines that replace
 * them, so a report says which of a line's operators survived.
 */
final class MutantDiff
{
    /**
     * @return list<string> `- ` lines of the original, then `+ ` lines of the mutant
     */
    public static function of(string $original, string $mutated): array
    {
        $before = explode("\n", $original);
        $after  = explode("\n", $mutated);
        $head   = 0;
        $limit  = min(count($before), count($after));

        while ($head < $limit && $before[$head] === $after[$head]) {
            $head++;
        }

        if ($head === count($before) && $head === count($after)) {
            return [];
        }

        $tail = 0;

        while ($tail < $limit - $head && $before[count($before) - 1 - $tail] === $after[count($after) - 1 - $tail]) {
            $tail++;
        }

        $lines = [];

        foreach (array_slice($before, $head, count($before) - $head - $tail) as $line) {
            $lines[] = '- ' . trim($line);
        }

        foreach (array_slice($after, $head, count($after) - $head - $tail) as $line) {
            $lines[] = '+ ' . trim($line);
        }

        return $lines;
    }
}
