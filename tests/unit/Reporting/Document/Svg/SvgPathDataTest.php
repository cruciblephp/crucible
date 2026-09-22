<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Svg;

use InvalidArgumentException;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Svg\SvgPathData;

#[CoversClass(SvgPathData::class)]
final class SvgPathDataTest extends TestCase
{
    private const float EPSILON = 1e-6;

    public function testAbsoluteMoveAndLineCommandsWithClose(): void
    {
        $subpaths = SvgPathData::parse('M10,10 L20,10 L20,20 Z');

        $this->assertCount(1, $subpaths);
        $this->assertSame([10.0, 10.0], $subpaths[0]['start']);
        $this->assertTrue($subpaths[0]['closed']);
        $this->assertSame([
            ['l', 20.0, 10.0],
            ['l', 20.0, 20.0],
        ], $subpaths[0]['segments']);
    }

    public function testRelativeMoveAndLineCommandsProduceTheSameShapeAsAbsolute(): void
    {
        $subpaths = SvgPathData::parse('m10,10 l10,0 l0,10 z');

        $this->assertSame([10.0, 10.0], $subpaths[0]['start']);
        $this->assertSame([
            ['l', 20.0, 10.0],
            ['l', 20.0, 20.0],
        ], $subpaths[0]['segments']);
    }

    public function testImplicitLinetosFollowingAMoveto(): void
    {
        $subpaths = SvgPathData::parse('M0,0 5,5 10,0');

        $this->assertSame([
            ['l', 5.0, 5.0],
            ['l', 10.0, 0.0],
        ], $subpaths[0]['segments']);
    }

    public function testHorizontalAndVerticalLineCommands(): void
    {
        $subpaths = SvgPathData::parse('M0,0 H10 V10 h-5 v-5');

        $this->assertSame([
            ['l', 10.0, 0.0],
            ['l', 10.0, 10.0],
            ['l', 5.0, 10.0],
            ['l', 5.0, 5.0],
        ], $subpaths[0]['segments']);
    }

    public function testCubicBezierCommand(): void
    {
        $subpaths = SvgPathData::parse('M0,0 C10,0 10,10 0,10');

        $this->assertSame([
            ['c', 10.0, 0.0, 10.0, 10.0, 0.0, 10.0],
        ], $subpaths[0]['segments']);
    }

    public function testSmoothCubicReflectsThePreviousControlPoint(): void
    {
        $subpaths = SvgPathData::parse('M0,0 C10,0 10,10 0,10 S-10,20 0,20');

        // Reflection of (10,10) about the current point (0,10) is (-10,10).
        $this->assertSame([
            ['c', 10.0, 0.0, 10.0, 10.0, 0.0, 10.0],
            ['c', -10.0, 10.0, -10.0, 20.0, 0.0, 20.0],
        ], $subpaths[0]['segments']);
    }

    public function testSmoothCubicWithNoPrecedingCubicUsesTheCurrentPointAsTheReflection(): void
    {
        $subpaths = SvgPathData::parse('M0,0 S10,10 0,10');

        $this->assertSame([
            ['c', 0.0, 0.0, 10.0, 10.0, 0.0, 10.0],
        ], $subpaths[0]['segments']);
    }

    public function testQuadraticBezierIsUpgradedToCubic(): void
    {
        $subpaths = SvgPathData::parse('M0,0 Q10,10 20,0');

        // Standard quad->cubic control points: x0 + 2/3*(qx-x0), etc.
        $this->assertEqualsWithDelta(
            ['c', 6.6666666666667, 6.6666666666667, 13.333333333333, 6.6666666666667, 20.0, 0.0],
            $subpaths[0]['segments'][0],
            self::EPSILON,
        );
    }

    public function testMultipleSubpathsFromSeparateMoveCommands(): void
    {
        $subpaths = SvgPathData::parse('M0,0 L10,0 M20,20 L30,20');

        $this->assertCount(2, $subpaths);
        $this->assertSame([0.0, 0.0], $subpaths[0]['start']);
        $this->assertFalse($subpaths[0]['closed']);
        $this->assertSame([20.0, 20.0], $subpaths[1]['start']);
    }

    public function testAZeroRadiusArcDegeneratesToAStraightLine(): void
    {
        $subpaths = SvgPathData::parse('M0,0 A0,0 0 0,1 10,10');

        $this->assertSame([['l', 10.0, 10.0]], $subpaths[0]['segments']);
    }

    public function testMalformedPathDataThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SvgPathData::parse('M0,0 L');
    }

    /**
     * A quarter-circle arc (unit radius, centered at the origin) has a
     * universally known closed-form cubic-Bézier approximation: the
     * "kappa" constant 0.5522847498... — verifying against these exact
     * published control points is a much stronger check than an
     * end-to-end visual comparison alone.
     */
    public function testArcToBeziersMatchesTheKnownQuarterCircleKappaConstant(): void
    {
        $beziers = SvgPathData::arcToBeziers(1.0, 0.0, 1.0, 1.0, 0.0, false, true, 0.0, 1.0);

        $this->assertCount(1, $beziers);
        [$cp1x, $cp1y, $cp2x, $cp2y, $x, $y] = $beziers[0];

        $kappa = 0.5522847498307936;

        $this->assertEqualsWithDelta(1.0, $cp1x, self::EPSILON);
        $this->assertEqualsWithDelta($kappa, $cp1y, self::EPSILON);
        $this->assertEqualsWithDelta($kappa, $cp2x, self::EPSILON);
        $this->assertEqualsWithDelta(1.0, $cp2y, self::EPSILON);
        $this->assertEqualsWithDelta(0.0, $x, self::EPSILON);
        $this->assertEqualsWithDelta(1.0, $y, self::EPSILON);
    }

    /** A half-circle (180°) must split into at least 2 segments, since each is capped at 90°. */
    public function testAHalfCircleArcSplitsIntoTwoSegments(): void
    {
        $beziers = SvgPathData::arcToBeziers(1.0, 0.0, 1.0, 1.0, 0.0, false, true, -1.0, 0.0);

        $this->assertCount(2, $beziers);
        // The arc's final endpoint must land exactly on the requested target.
        [, , , , $x, $y] = $beziers[1];
        $this->assertEqualsWithDelta(-1.0, $x, self::EPSILON);
        $this->assertEqualsWithDelta(0.0, $y, self::EPSILON);
    }

    /**
     * Toggling the large-arc flag (sweep held fixed) must pick the
     * complementary route around the circle it lands on — a short
     * (<=90°, 1 segment) arc versus a long (270°, 3 segments) one.
     */
    public function testLargeArcFlagChangesArcExtent(): void
    {
        $shortArc = SvgPathData::arcToBeziers(1.0, 0.0, 1.0, 1.0, 0.0, false, true, 0.0, 1.0);
        $longArc  = SvgPathData::arcToBeziers(1.0, 0.0, 1.0, 1.0, 0.0, true, true, 0.0, 1.0);

        $this->assertCount(1, $shortArc);
        $this->assertCount(3, $longArc);
    }

    /** An arc whose start equals its end is degenerate and produces no line/curve at all (handled by the caller, not arcToBeziers itself, so this exercises the parser's own guard). */
    public function testDegenerateSameStartAndEndArcInPathData(): void
    {
        $subpaths = SvgPathData::parse('M5,5 A2,2 0 0,1 5,5 L10,10');

        // The degenerate arc contributes nothing; only the following line remains.
        $this->assertSame([['l', 10.0, 10.0]], $subpaths[0]['segments']);
    }
}
