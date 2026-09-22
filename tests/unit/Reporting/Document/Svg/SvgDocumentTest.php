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
use LucianoPereira\Crucible\Reporting\Document\Svg\SvgDocument;

use function substr_count;
use function trim;

#[CoversClass(SvgDocument::class)]
final class SvgDocumentTest extends TestCase
{
    public function testViewBoxIsReadFromTheViewBoxAttribute(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 200 100"></svg>');

        $this->assertSame(0.0, $result['minX']);
        $this->assertSame(0.0, $result['minY']);
        $this->assertSame(200.0, $result['width']);
        $this->assertSame(100.0, $result['height']);
    }

    public function testViewBoxFallsBackToWidthAndHeightAttributes(): void
    {
        $result = (new SvgDocument())->render('<svg width="50" height="25"></svg>');

        $this->assertSame(50.0, $result['width']);
        $this->assertSame(25.0, $result['height']);
    }

    public function testNoViewBoxOrDimensionsThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg></svg>');
    }

    public function testARectEmitsTheReOperatorAndAFillColor(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect x="1" y="2" width="3" height="4" fill="#ff0000"/></svg>');

        $this->assertStringContainsString('1.0000 2.0000 3.0000 4.0000 re', $result['ops']);
        $this->assertStringContainsString('1.0000 0.0000 0.0000 rg', $result['ops']);
        $this->assertStringContainsString("\nf", $result['ops']);
    }

    /**
     * Every corner coordinate, not just the presence of a curve.
     *
     * ⚠ `crucible mutate` escaped 95 mutants in this file, 61 of them in
     * the two Bézier blocks, because the assertions were `' c'` and
     * `'h'` appear — true for any arithmetic at all. The four corners are
     * ~40 additions and multiplications by the kappa constant, and
     * nothing looked at a single result.
     *
     * ✓ The expected stream was computed independently from
     * k = 0.5522847498 and x/y/w/h/rx before being compared with the
     * output, so this pins the geometry rather than recording it.
     */
    public function testARoundedRectEmitsEveryBezierCornerCoordinate(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect x="0" y="0" width="10" height="10" rx="2"/></svg>');

        self::assertSame(
            "2.0000 0.0000 m\n"
            . "8.0000 0.0000 l\n"
            . "9.1046 0.0000 10.0000 0.8954 10.0000 2.0000 c\n"
            . "10.0000 8.0000 l\n"
            . "10.0000 9.1046 9.1046 10.0000 8.0000 10.0000 c\n"
            . "2.0000 10.0000 l\n"
            . "0.8954 10.0000 0.0000 9.1046 0.0000 8.0000 c\n"
            . "0.0000 2.0000 l\n"
            . "0.0000 0.8954 0.8954 0.0000 2.0000 0.0000 c\n"
            . "h\n"
            . "0.0000 0.0000 0.0000 rg\n"
            . "f\n",
            $result['ops'],
        );
    }

    /** rx defaults to ry, so one radius rounds all four corners equally. */
    public function testASingleRadiusRoundsBothAxes(): void
    {
        $oneRadius = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" rx="2"/></svg>');
        $both      = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" rx="2" ry="2"/></svg>');

        self::assertSame($both['ops'], $oneRadius['ops']);
    }

    /**
     * ✓ Computed independently from the same kappa: a circle is four
     * arcs whose control points sit k*r from each quadrant end.
     */
    public function testACircleEmitsEveryBezierArcCoordinate(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="blue"/></svg>');

        self::assertSame(
            "9.0000 5.0000 m\n"
            . "9.0000 7.2091 7.2091 9.0000 5.0000 9.0000 c\n"
            . "2.7909 9.0000 1.0000 7.2091 1.0000 5.0000 c\n"
            . "1.0000 2.7909 2.7909 1.0000 5.0000 1.0000 c\n"
            . "7.2091 1.0000 9.0000 2.7909 9.0000 5.0000 c\n"
            . "h\n"
            . "0.0000 0.0000 1.0000 rg\n"
            . "f\n",
            $result['ops'],
        );
    }

    /** An ellipse is the circle with the two radii pulled apart. */
    public function testAnEllipseUsesEachRadiusOnItsOwnAxis(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 20 10"><ellipse cx="10" cy="5" rx="8" ry="4"/></svg>');

        self::assertStringContainsString("18.0000 5.0000 m\n", $result['ops']);
        self::assertStringContainsString("18.0000 7.2091 14.4183 9.0000 10.0000 9.0000 c\n", $result['ops']);
        self::assertSame(4, substr_count($result['ops'], ' c'));
    }

    /** A zero or negative radius is a plain rectangle, not a degenerate curve. */
    public function testANonPositiveRadiusFallsBackToTheReOperator(): void
    {
        foreach (['0', '-1'] as $radius) {
            $result = (new SvgDocument())->render(
                '<svg viewBox="0 0 10 10"><rect x="1" y="2" width="3" height="4" rx="' . $radius . '"/></svg>',
            );

            self::assertStringContainsString('1.0000 2.0000 3.0000 4.0000 re', $result['ops'], 'rx=' . $radius);
            self::assertStringNotContainsString(' c', $result['ops'], 'rx=' . $radius);
        }
    }

    public function testAPolygonClosesItsPath(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><polygon points="0,0 5,0 5,5"/></svg>');

        $this->assertStringContainsString('0.0000 0.0000 m', $result['ops']);
        $this->assertStringContainsString('5.0000 0.0000 l', $result['ops']);
        $this->assertStringContainsString('5.0000 5.0000 l', $result['ops']);
        $this->assertStringContainsString('h', $result['ops']);
    }

    public function testAPolylineDoesNotClose(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><polyline points="0,0 5,0 5,5"/></svg>');

        $this->assertStringNotContainsString('h', $result['ops']);
    }

    public function testALineDrawsAsAStrokeByDefaultSinceLinesHaveNoFillArea(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><line x1="0" y1="0" x2="10" y2="10" stroke="red"/></svg>');

        $this->assertStringContainsString('0.0000 0.0000 m', $result['ops']);
        $this->assertStringContainsString('10.0000 10.0000 l', $result['ops']);
    }

    public function testAPathWithAnArcRendersCurveOperators(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><path d="M0,5 A5,5 0 0,1 10,5"/></svg>');

        $this->assertStringContainsString(' c', $result['ops']);
    }

    public function testFillAndStrokeBothPresentUseTheBOperator(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="red" stroke="blue"/></svg>');

        $this->assertMatchesRegularExpression('/\nB\b/', $result['ops']);
    }

    public function testFillNoneWithStrokeUsesTheSOperator(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="none" stroke="blue"/></svg>');

        $this->assertMatchesRegularExpression('/\nS\b/', $result['ops']);
        $this->assertStringNotContainsString('rg', $result['ops']);
    }

    public function testFillRuleEvenoddUsesTheStarOperator(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><path d="M0,0 L10,0 L10,10 Z" fill-rule="evenodd"/></svg>');

        $this->assertStringContainsString('f*', $result['ops']);
    }

    public function testStrokeWidthEmitsTheWOperator(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" stroke="red" stroke-width="3"/></svg>');

        $this->assertStringContainsString('3.0000 w', $result['ops']);
    }

    public function testRgbFunctionColorSyntax(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="rgb(255,128,0)"/></svg>');

        $this->assertStringContainsString('1.0000 0.5020 0.0000 rg', $result['ops']);
    }

    public function testShortHexColorSyntax(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="#0f0"/></svg>');

        $this->assertStringContainsString('0.0000 1.0000 0.0000 rg', $result['ops']);
    }

    public function testInlineStyleAttributeIsHonoredForPresentationProperties(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" style="fill:#ff0000;stroke:none"/></svg>');

        $this->assertStringContainsString('1.0000 0.0000 0.0000 rg', $result['ops']);
    }

    public function testATranslateTransformNestsAQCmBlock(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><g transform="translate(5,5)"><rect width="1" height="1"/></g></svg>');

        $this->assertStringContainsString('1.0000 0.0000 0.0000 1.0000 5.0000 5.0000 cm', $result['ops']);
    }

    public function testAScaleTransform(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><g transform="scale(2,3)"><rect width="1" height="1"/></g></svg>');

        $this->assertStringContainsString('2.0000 0.0000 0.0000 3.0000 0.0000 0.0000 cm', $result['ops']);
    }

    public function testFillIsInheritedFromAnAncestorGroup(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><g fill="purple"><rect width="1" height="1"/></g></svg>');

        $this->assertStringContainsString('0.5020 0.0000 0.5020 rg', $result['ops']);
    }

    public function testAChildOverridesTheInheritedFill(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><g fill="purple"><rect width="1" height="1" fill="lime"/></g></svg>');

        $this->assertStringContainsString('0.0000 1.0000 0.0000 rg', $result['ops']);
        $this->assertStringNotContainsString('0.5020 0.0000 0.5020 rg', $result['ops']);
    }

    public function testAnUnusedDefsBlockRendersNothing(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><defs><rect width="5" height="5" fill="red"/></defs></svg>');

        $this->assertSame('', trim($result['ops']));
    }

    public function testDefaultFillIsBlackWhenUnspecified(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10"/></svg>');

        $this->assertStringContainsString('0.0000 0.0000 0.0000 rg', $result['ops']);
    }

    // -- Unsupported features: must throw, never silently mis-render --

    public function testGradientFillThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" fill="url(#grad)"/></svg>');
    }

    public function testFilterAttributeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" filter="url(#f)"/></svg>');
    }

    public function testClipPathAttributeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" clip-path="url(#c)"/></svg>');
    }

    public function testMaskAttributeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" mask="url(#m)"/></svg>');
    }

    public function testTextElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><text x="0" y="0">hi</text></svg>');
    }

    public function testEmbeddedImageElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><image href="x.jpg" width="10" height="10"/></svg>');
    }

    public function testStyleElementThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><style>rect{fill:red}</style><rect width="10" height="10"/></svg>');
    }

    public function testNonOneOpacityThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" opacity="0.5"/></svg>');
    }

    public function testFullOpacityDoesNotThrow(): void
    {
        $result = (new SvgDocument())->render('<svg viewBox="0 0 10 10"><rect width="10" height="10" opacity="1"/></svg>');

        $this->assertNotSame('', trim($result['ops']));
    }
}
