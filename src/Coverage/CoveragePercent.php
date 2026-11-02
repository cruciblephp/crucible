<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

use function floor;
use function round;

/**
 * A coverage score as printed and as judged, one number for both: floored
 * at two places, never rounded up, so 99.996% reads 99.99% and fails a
 * floor of 100, as PHPUnit truncates. A typed floor is rounded to two
 * places instead, since it is a number someone wrote, not a measurement.
 */
final class CoveragePercent
{
    public static function of(int $covered, int $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        // Rounded to six places first: 8.07 * 100 is 806.999… in binary,
        // and flooring that would lose a whole hundredth.
        return floor(round(100 * $covered / $total * 100, 6)) / 100;
    }

    public static function floor(float $minimum): float
    {
        return round($minimum, 2);
    }
}
