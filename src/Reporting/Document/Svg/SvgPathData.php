<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Svg;

use InvalidArgumentException;

use function abs;
use function acos;
use function ceil;
use function cos;
use function count;
use function is_numeric;
use function max;
use function min;
use function preg_match_all;
use function sin;
use function sqrt;
use function strlen;
use function strtolower;
use function strtoupper;
use function tan;

use const M_PI;

/**
 * Parses an SVG `<path d="...">` attribute into a normalized list of
 * subpaths, each a moveto followed by line/curve segments in absolute
 * coordinates — quadratics upgraded to cubics (PDF's `c` operator is the
 * only curve primitive it has) and arcs converted via the standard
 * endpoint-to-center parameterization (SVG spec Appendix F.6), the same
 * technique every SVG-to-PDF/Canvas converter uses since PDF has no arc
 * operator of its own.
 *
 * @phpstan-type Segment array{0: 'l', 1: float, 2: float}|array{0: 'c', 1: float, 2: float, 3: float, 4: float, 5: float, 6: float}
 * @phpstan-type Subpath array{start: array{float, float}, segments: list<Segment>, closed: bool}
 */
final class SvgPathData
{
    /**
     * @return list<Subpath>
     */
    public static function parse(string $d): array
    {
        $tokens = self::tokenize($d);
        $pos    = 0;
        $count  = count($tokens);

        /** @var list<Subpath> $subpaths */
        $subpaths = [];

        $currentX = 0.0;
        $currentY = 0.0;
        $startX   = 0.0;
        $startY   = 0.0;

        /** @var list<Segment> $segments */
        $segments = [];
        $hasOpen  = false;

        // The absolute position of the previous cubic/quadratic control
        // point, used to reflect S/s and T/t's implicit control point —
        // null whenever the previous command wasn't the matching curve
        // type, per spec ("equivalent to specifying the current point
        // as the reflection").
        $prevCubicControl = null;
        $prevQuadControl  = null;

        while ($pos < $count) {
            $command = $tokens[$pos];
            if (!self::isCommandLetter($command)) {
                throw new InvalidArgumentException("Expected an SVG path command letter, found \"{$command}\".");
            }

            $pos++;
            $isRelative = $command === strtolower($command);
            $upper      = strtoupper($command);

            if ($upper !== 'S' && $upper !== 'T') {
                $prevCubicControl = $upper === 'C' ? $prevCubicControl : null;
                $prevQuadControl  = $upper === 'Q' ? $prevQuadControl : null;
            }

            switch ($upper) {
                case 'M':
                    [$x, $y, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);

                    if ($hasOpen) {
                        $subpaths[] = ['start' => [$startX, $startY], 'segments' => $segments, 'closed' => false];
                    }

                    $currentX = $startX = $x;
                    $currentY = $startY = $y;
                    $segments = [];
                    $hasOpen  = true;

                    // Subsequent coordinate pairs after the initial
                    // moveto are implicit linetos — 0 more is valid here.
                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        [$x, $y, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);
                        $segments[]    = ['l', $x, $y];
                        $currentX      = $x;
                        $currentY      = $y;
                    }

                    break;

                case 'L':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        [$x, $y, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);
                        $segments[]    = ['l', $x, $y];
                        $currentX      = $x;
                        $currentY      = $y;
                    }

                    break;

                case 'H':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        $value = self::readNumber($tokens, $pos);
                        $pos++;
                        $currentX   = $isRelative ? $currentX + $value : $value;
                        $segments[] = ['l', $currentX, $currentY];
                    }

                    break;

                case 'V':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        $value = self::readNumber($tokens, $pos);
                        $pos++;
                        $currentY   = $isRelative ? $currentY + $value : $value;
                        $segments[] = ['l', $currentX, $currentY];
                    }

                    break;

                case 'C':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        [$x1, $y1, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);
                        [$x2, $y2, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);
                        [$x, $y, $pos]   = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);

                        $segments[]       = ['c', $x1, $y1, $x2, $y2, $x, $y];
                        $prevCubicControl = [$x2, $y2];
                        $currentX         = $x;
                        $currentY         = $y;
                    }

                    break;

                case 'S':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        [$x2, $y2, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);
                        [$x, $y, $pos]   = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);

                        $x1 = $prevCubicControl !== null ? 2 * $currentX - $prevCubicControl[0] : $currentX;
                        $y1 = $prevCubicControl !== null ? 2 * $currentY - $prevCubicControl[1] : $currentY;

                        $segments[]       = ['c', $x1, $y1, $x2, $y2, $x, $y];
                        $prevCubicControl = [$x2, $y2];
                        $currentX         = $x;
                        $currentY         = $y;
                    }

                    break;

                case 'Q':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        [$qx, $qy, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);
                        [$x, $y, $pos]   = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);

                        $segments[]      = self::quadToCubic($currentX, $currentY, $qx, $qy, $x, $y);
                        $prevQuadControl = [$qx, $qy];
                        $currentX        = $x;
                        $currentY        = $y;
                    }

                    break;

                case 'T':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        [$x, $y, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);

                        $qx = $prevQuadControl !== null ? 2 * $currentX - $prevQuadControl[0] : $currentX;
                        $qy = $prevQuadControl !== null ? 2 * $currentY - $prevQuadControl[1] : $currentY;

                        $segments[]      = self::quadToCubic($currentX, $currentY, $qx, $qy, $x, $y);
                        $prevQuadControl = [$qx, $qy];
                        $currentX        = $x;
                        $currentY        = $y;
                    }

                    break;

                case 'A':
                    self::requireArgument($tokens, $pos, $count, $command);

                    while ($pos < $count && !self::isCommandLetter($tokens[$pos])) {
                        $rx            = self::readNumber($tokens, $pos++);
                        $ry            = self::readNumber($tokens, $pos++);
                        $xRotation     = self::readNumber($tokens, $pos++);
                        $largeArc      = self::readNumber($tokens, $pos++) !== 0.0;
                        $sweep         = self::readNumber($tokens, $pos++) !== 0.0;
                        [$x, $y, $pos] = self::readPoint($tokens, $pos, $isRelative, $currentX, $currentY);

                        if ($currentX === $x && $currentY === $y) {
                            // Per spec: identical endpoints omit the arc
                            // entirely, not even as a zero-length line.
                        } elseif ($rx === 0.0 || $ry === 0.0) {
                            $segments[] = ['l', $x, $y];
                        } else {
                            foreach (self::arcToBeziers($currentX, $currentY, $rx, $ry, $xRotation, $largeArc, $sweep, $x, $y) as $bezier) {
                                $segments[] = ['c', ...$bezier];
                            }
                        }

                        $currentX = $x;
                        $currentY = $y;
                    }

                    break;

                case 'Z':
                    $subpaths[] = ['start' => [$startX, $startY], 'segments' => $segments, 'closed' => true];
                    $currentX   = $startX;
                    $currentY   = $startY;
                    $segments   = [];
                    $hasOpen    = false;

                    break;
            }
        }

        if ($hasOpen) {
            $subpaths[] = ['start' => [$startX, $startY], 'segments' => $segments, 'closed' => false];
        }

        return $subpaths;
    }

    /**
     * Converts an SVG elliptical-arc endpoint segment into a list of
     * absolute cubic Bézier curves — endpoint-to-center parameterization
     * (SVG spec F.6.5/F.6.6) followed by splitting the resulting arc
     * into ≤90°-sweep segments, each approximated with the standard
     * kappa-derived control-point formula.
     *
     * @return list<array{float, float, float, float, float, float}> [cp1x, cp1y, cp2x, cp2y, x, y] segments
     */
    public static function arcToBeziers(
        float $x1,
        float $y1,
        float $rx,
        float $ry,
        float $xAxisRotationDeg,
        bool $largeArc,
        bool $sweep,
        float $x2,
        float $y2,
    ): array {
        $rx  = abs($rx);
        $ry  = abs($ry);
        $phi = $xAxisRotationDeg * M_PI / 180.0;

        $cosPhi = cos($phi);
        $sinPhi = sin($phi);

        // F.6.5 step 1: move to the rotated coordinate system centered
        // between the two endpoints.
        $dx2 = ($x1 - $x2) / 2.0;
        $dy2 = ($y1 - $y2) / 2.0;
        $x1p = $cosPhi * $dx2 + $sinPhi * $dy2;
        $y1p = -$sinPhi * $dx2 + $cosPhi * $dy2;

        // F.6.6: scale up rx/ry if the endpoints are further apart than
        // the given radii could ever reach.
        $lambda = ($x1p * $x1p) / ($rx * $rx) + ($y1p * $y1p) / ($ry * $ry);
        if ($lambda > 1.0) {
            $scale = sqrt($lambda);
            $rx *= $scale;
            $ry *= $scale;
        }

        // F.6.5 step 2: the center in the rotated coordinate system.
        $rxSq  = $rx * $rx;
        $rySq  = $ry * $ry;
        $x1pSq = $x1p * $x1p;
        $y1pSq = $y1p * $y1p;

        $num = max(0.0, $rxSq * $rySq - $rxSq * $y1pSq - $rySq * $x1pSq);
        $den = $rxSq * $y1pSq + $rySq * $x1pSq;
        $co  = $den > 0.0 ? sqrt($num / $den) : 0.0;
        $co  = $largeArc === $sweep ? -$co : $co;

        $cxp = $co * ($rx * $y1p / $ry);
        $cyp = $co * -($ry * $x1p / $rx);

        // F.6.5 step 3: the center in the original coordinate system.
        $cx = $cosPhi * $cxp - $sinPhi * $cyp + ($x1 + $x2) / 2.0;
        $cy = $sinPhi * $cxp + $cosPhi * $cyp + ($y1 + $y2) / 2.0;

        // F.6.5 step 4: start/sweep angles.
        $theta1 = self::angleBetween(1.0, 0.0, ($x1p - $cxp) / $rx, ($y1p - $cyp) / $ry);
        $delta  = self::angleBetween(
            ($x1p - $cxp) / $rx,
            ($y1p - $cyp) / $ry,
            (-$x1p - $cxp) / $rx,
            (-$y1p - $cyp) / $ry,
        );

        if (!$sweep && $delta > 0.0) {
            $delta -= 2.0 * M_PI;
        } elseif ($sweep && $delta < 0.0) {
            $delta += 2.0 * M_PI;
        }

        $segmentCount = (int) ceil(abs($delta) / (M_PI / 2.0));
        $segmentCount = max($segmentCount, 1);
        $segmentDelta = $delta / $segmentCount;

        $beziers = [];

        for ($i = 0; $i < $segmentCount; $i++) {
            $start     = $theta1 + $i * $segmentDelta;
            $end       = $start + $segmentDelta;
            $beziers[] = self::unitArcToBezier($start, $end, $rx, $ry, $cx, $cy, $cosPhi, $sinPhi);
        }

        return $beziers;
    }

    /**
     * One ≤90° arc segment (in the ellipse's own parameter space, from
     * angle $start to $end) as a single cubic Bézier, mapped through the
     * ellipse's radii/rotation/center back into path coordinates. The
     * `4/3 * tan(delta/4)` control-point-distance formula is the
     * standard circular/elliptical-arc Bézier approximation.
     *
     * @return array{float, float, float, float, float, float}
     */
    private static function unitArcToBezier(float $start, float $end, float $rx, float $ry, float $cx, float $cy, float $cosPhi, float $sinPhi): array
    {
        $alpha = 4.0 / 3.0 * tan(($end - $start) / 4.0);

        $cosStart = cos($start);
        $sinStart = sin($start);
        $cosEnd   = cos($end);
        $sinEnd   = sin($end);
        $p2x      = $cosStart - $alpha * $sinStart;
        $p2y      = $sinStart + $alpha * $cosStart;
        $p3x      = $cosEnd + $alpha * $sinEnd;
        $p3y      = $sinEnd - $alpha * $cosEnd;
        $p4x      = $cosEnd;
        $p4y      = $sinEnd;

        return [
            ...self::ellipsePoint($p2x, $p2y, $rx, $ry, $cx, $cy, $cosPhi, $sinPhi),
            ...self::ellipsePoint($p3x, $p3y, $rx, $ry, $cx, $cy, $cosPhi, $sinPhi),
            ...self::ellipsePoint($p4x, $p4y, $rx, $ry, $cx, $cy, $cosPhi, $sinPhi),
        ];
    }

    /** @return array{float, float} */
    private static function ellipsePoint(float $unitX, float $unitY, float $rx, float $ry, float $cx, float $cy, float $cosPhi, float $sinPhi): array
    {
        $x = $rx * $unitX;
        $y = $ry * $unitY;

        return [
            $cosPhi * $x - $sinPhi * $y + $cx,
            $sinPhi * $x + $cosPhi * $y + $cy,
        ];
    }

    /** The signed angle (radians) from vector (ux,uy) to vector (vx,vy). */
    private static function angleBetween(float $ux, float $uy, float $vx, float $vy): float
    {
        $sign = $ux * $vy - $uy * $vx < 0.0 ? -1.0 : 1.0;
        $dot  = $ux * $vx + $uy * $vy;
        $lenU = sqrt($ux * $ux + $uy * $uy);
        $lenV = sqrt($vx * $vx + $vy * $vy);
        $cos  = $lenU * $lenV > 0.0 ? max(-1.0, min(1.0, $dot / ($lenU * $lenV))) : 1.0;

        return $sign * acos($cos);
    }

    /** @return array{'c', float, float, float, float, float, float} */
    private static function quadToCubic(float $x0, float $y0, float $qx, float $qy, float $x1, float $y1): array
    {
        return [
            'c',
            $x0 + 2.0 / 3.0 * ($qx - $x0),
            $y0 + 2.0 / 3.0 * ($qy - $y0),
            $x1 + 2.0 / 3.0 * ($qx - $x1),
            $y1 + 2.0 / 3.0 * ($qy - $y1),
            $x1,
            $y1,
        ];
    }

    /**
     * @param list<string> $tokens
     * @return array{float, float, int}
     */
    private static function readPoint(array $tokens, int $pos, bool $isRelative, float $currentX, float $currentY): array
    {
        $x = self::readNumber($tokens, $pos);
        $y = self::readNumber($tokens, $pos + 1);

        if ($isRelative) {
            $x += $currentX;
            $y += $currentY;
        }

        return [$x, $y, $pos + 2];
    }

    /**
     * Every repeatable path command (M/L/H/V/C/S/Q/T/A) requires at
     * least one argument set — the `while` loops that read repeats
     * happily accept zero, so this guards the case the loop itself
     * can't catch: a bare command letter with nothing following it.
     *
     * @param list<string> $tokens
     */
    private static function requireArgument(array $tokens, int $pos, int $count, string $command): void
    {
        if ($pos >= $count || self::isCommandLetter($tokens[$pos])) {
            throw new InvalidArgumentException("SVG path command \"{$command}\" is missing its required argument(s).");
        }
    }

    /** @param list<string> $tokens */
    private static function readNumber(array $tokens, int $pos): float
    {
        if (!isset($tokens[$pos]) || !is_numeric($tokens[$pos])) {
            throw new InvalidArgumentException('Malformed SVG path data: expected a number.');
        }

        return (float) $tokens[$pos];
    }

    private static function isCommandLetter(string $token): bool
    {
        return strlen($token) === 1 && isset(self::COMMAND_LETTERS[strtoupper($token)]);
    }

    /** @var array<string, true> */
    private const array COMMAND_LETTERS = [
        'M' => true, 'L' => true, 'H' => true, 'V' => true,
        'C' => true, 'S' => true, 'Q' => true, 'T' => true,
        'A' => true, 'Z' => true,
    ];

    /**
     * Splits path data into command letters and numbers. SVG's loose
     * separator rules (commas, whitespace, or nothing at all between a
     * sign-prefixed number and its predecessor) are all accepted; the
     * one documented gap is packed arc flags with no separator at all
     * (e.g. `A5,5,0,0110,10` for large-arc=1,sweep=1) — real-world tool
     * output almost always separates them, and unpacking that specific
     * ambiguity isn't attempted here.
     *
     * @return list<string>
     */
    private static function tokenize(string $d): array
    {
        preg_match_all('/[MmLlHhVvCcSsQqTtAaZz]|[-+]?(?:\d*\.\d+|\d+\.?\d*)(?:[eE][-+]?\d+)?/', $d, $matches);

        return $matches[0];
    }
}
