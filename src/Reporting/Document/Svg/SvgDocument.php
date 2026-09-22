<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Svg;

use DOMDocument;
use DOMElement;
use InvalidArgumentException;

use function array_filter;
use function array_map;
use function array_values;
use function cos;
use function count;
use function explode;
use function hexdec;
use function implode;
use function in_array;
use function is_numeric;
use function libxml_use_internal_errors;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function rtrim;
use function sin;
use function sprintf;
use function str_repeat;
use function str_starts_with;
use function strtolower;
use function substr;
use function tan;
use function trim;

use const M_PI;
use const PREG_SET_ORDER;

/**
 * Walks an SVG document's DOM tree and emits the raw PDF content-stream
 * operators (in the SVG's own unscaled coordinate space) that draw it —
 * `PdfPrimitives::svg()` wraps the result in one outer `cm` mapping that
 * space into the placed box. No `/Resources`/`XObject` involvement at
 * all: unlike a JPEG, every shape draws as vector operators directly in
 * the page's content stream, the same way `box()`/`rule()`/`disc()`
 * already do.
 *
 * Explicitly unsupported (throws `InvalidArgumentException` naming the
 * feature, never silently mis-renders): gradients/patterns, `<filter>`,
 * `clip-path`/`mask`, `<text>`, embedded `<image>`, `<style>`/CSS-class
 * presentation, and non-1 opacity/blend-modes.
 *
 * @phpstan-import-type Subpath from SvgPathData
 * @phpstan-type PaintContext array{fill: ?array{float, float, float}, stroke: ?array{float, float, float}, strokeWidth: float, evenOdd: bool}
 * @phpstan-type Rendered array{ops: string, minX: float, minY: float, width: float, height: float}
 */
final class SvgDocument
{
    private const array NAMED_COLORS = [
        'black'   => [0, 0, 0], 'silver' => [192, 192, 192], 'gray' => [128, 128, 128], 'grey' => [128, 128, 128],
        'white'   => [255, 255, 255], 'maroon' => [128, 0, 0], 'red' => [255, 0, 0], 'purple' => [128, 0, 128],
        'fuchsia' => [255, 0, 255], 'magenta' => [255, 0, 255], 'green' => [0, 128, 0], 'lime' => [0, 255, 0],
        'olive'   => [128, 128, 0], 'yellow' => [255, 255, 0], 'navy' => [0, 0, 128], 'blue' => [0, 0, 255],
        'teal'    => [0, 128, 128], 'aqua' => [0, 255, 255], 'cyan' => [0, 255, 255], 'orange' => [255, 165, 0],
    ];

    /** @return Rendered */
    public function render(string $markup): array
    {
        $dom      = new DOMDocument();
        $previous = libxml_use_internal_errors(true);

        try {
            if (!$dom->loadXML($markup)) {
                throw new InvalidArgumentException('Could not parse SVG markup as XML.');
            }
        } finally {
            libxml_use_internal_errors($previous);
        }

        $root = $dom->documentElement;
        if (!$root instanceof DOMElement || strtolower($root->localName ?? '') !== 'svg') {
            throw new InvalidArgumentException('SVG markup has no root <svg> element.');
        }

        if ($root->getElementsByTagName('style')->count() > 0) {
            throw new InvalidArgumentException('CSS <style> elements are not supported in SVG images.');
        }

        [$minX, $minY, $width, $height] = $this->viewBox($root);

        $context = ['fill' => [0.0, 0.0, 0.0], 'stroke' => null, 'strokeWidth' => 1.0, 'evenOdd' => false];
        $ops     = $this->walkChildren($root, $context);

        return ['ops' => $ops, 'minX' => $minX, 'minY' => $minY, 'width' => $width, 'height' => $height];
    }

    /** @return array{float, float, float, float} [minX, minY, width, height] */
    private function viewBox(DOMElement $svg): array
    {
        $viewBox = trim($svg->getAttribute('viewBox'));

        if ($viewBox !== '') {
            $split = preg_split('/[\s,]+/', $viewBox);
            $parts = $split !== false ? $split : [];
            if (count($parts) === 4 && is_numeric($parts[0]) && is_numeric($parts[1]) && is_numeric($parts[2]) && is_numeric($parts[3])) {
                return [(float) $parts[0], (float) $parts[1], (float) $parts[2], (float) $parts[3]];
            }

            throw new InvalidArgumentException("Malformed SVG viewBox attribute: \"{$viewBox}\".");
        }

        $width  = $this->numericAttribute($svg, 'width');
        $height = $this->numericAttribute($svg, 'height');

        if ($width === null || $height === null) {
            throw new InvalidArgumentException('SVG has no viewBox and no numeric width/height to establish its coordinate space.');
        }

        return [0.0, 0.0, $width, $height];
    }

    private function numericAttribute(DOMElement $el, string $name): ?float
    {
        $value = trim($el->getAttribute($name));
        if ($value === '') {
            return null;
        }

        // Strip a trailing unit (px, pt, mm, ...) — treated as bare
        // user-unit numbers, matching the unitless viewBox convention.
        if (preg_match('/^(-?[\d.]+)/', $value, $m) !== 1) {
            return null;
        }

        return (float) $m[1];
    }

    /** @param PaintContext $context */
    private function walkChildren(DOMElement $parent, array $context): string
    {
        $ops = '';

        foreach ($parent->childNodes as $child) {
            if (!$child instanceof DOMElement) {
                continue;
            }

            $ops .= $this->walkElement($child, $context);
        }

        return $ops;
    }

    /** @param PaintContext $context */
    private function walkElement(DOMElement $el, array $context): string
    {
        $tag = strtolower($el->localName ?? '');

        // Non-visual containers and definitions — never rendered, and
        // never recursed into (a <defs> block full of unused shapes
        // must not draw anything).
        if (in_array($tag, ['defs', 'symbol', 'marker', 'clippath', 'mask', 'pattern', 'lineargradient', 'radialgradient', 'title', 'desc', 'metadata'], true)) {
            return '';
        }

        if ($tag === 'text') {
            throw new InvalidArgumentException('SVG <text> elements are not supported.');
        }

        if ($tag === 'image') {
            throw new InvalidArgumentException('Embedded <image> elements inside SVG are not supported.');
        }

        $this->rejectUnsupportedAttributes($el);

        $context  = $this->resolvePaint($el, $context);
        $matrices = $this->parseTransformList($el->getAttribute('transform'));

        $body = match ($tag) {
            'g', 'svg', 'a' => $this->walkChildren($el, $context),
            'rect'          => $this->drawRect($el, $context),
            'circle'        => $this->drawEllipse($this->numericAttr($el, 'cx'), $this->numericAttr($el, 'cy'), $this->numericAttr($el, 'r'), $this->numericAttr($el, 'r'), $context),
            'ellipse'       => $this->drawEllipse($this->numericAttr($el, 'cx'), $this->numericAttr($el, 'cy'), $this->numericAttr($el, 'rx'), $this->numericAttr($el, 'ry'), $context),
            'line'          => $this->drawSubpaths([['start' => [$this->numericAttr($el, 'x1'), $this->numericAttr($el, 'y1')], 'segments' => [['l', $this->numericAttr($el, 'x2'), $this->numericAttr($el, 'y2')]], 'closed' => false]], $context),
            'polyline'      => $this->drawSubpaths([$this->pointsToSubpath($el, false)], $context),
            'polygon'       => $this->drawSubpaths([$this->pointsToSubpath($el, true)], $context),
            'path'          => $this->drawSubpaths(SvgPathData::parse($el->getAttribute('d')), $context),
            default         => '',
        };

        if ($matrices === []) {
            return $body;
        }

        $open  = implode('', array_map(static fn(array $m): string => sprintf("q %s %s %s %s %s %s cm\n", ...array_map(self::number(...), $m)), $matrices));
        $close = str_repeat("Q\n", count($matrices));

        return $open . $body . $close;
    }

    /**
     * Throws for the presentation-level features this "basic subset"
     * doesn't attempt: filters, clip-path/mask, and non-1 opacity —
     * checked once per element regardless of which shape it draws.
     */
    private function rejectUnsupportedAttributes(DOMElement $el): void
    {
        $style = $this->inlineStyle($el);

        foreach (['filter' => 'SVG filters', 'clip-path' => 'SVG clip-paths', 'mask' => 'SVG masks'] as $attr => $label) {
            $value = $style[$attr] ?? $el->getAttribute($attr);
            if ($value !== '' && strtolower(trim($value)) !== 'none') {
                throw new InvalidArgumentException("{$label} are not supported in SVG images.");
            }
        }

        foreach (['opacity', 'fill-opacity', 'stroke-opacity'] as $attr) {
            $value = $style[$attr] ?? $el->getAttribute($attr);
            if ($value !== '' && !is_numeric($value)) {
                continue;
            }

            if ($value !== '' && (float) $value !== 1.0) {
                throw new InvalidArgumentException('Opacity/blend-modes are not supported in SVG images.');
            }
        }

        $mixBlend = $style['mix-blend-mode'] ?? '';
        if ($mixBlend !== '' && strtolower($mixBlend) !== 'normal') {
            throw new InvalidArgumentException('Opacity/blend-modes are not supported in SVG images.');
        }
    }

    /**
     * @param  PaintContext  $inherited
     * @return PaintContext
     */
    private function resolvePaint(DOMElement $el, array $inherited): array
    {
        $style = $this->inlineStyle($el);

        $fillValue   = $style['fill'] ?? ($el->hasAttribute('fill') ? $el->getAttribute('fill') : null);
        $strokeValue = $style['stroke'] ?? ($el->hasAttribute('stroke') ? $el->getAttribute('stroke') : null);
        $widthValue  = $style['stroke-width'] ?? ($el->hasAttribute('stroke-width') ? $el->getAttribute('stroke-width') : null);
        $ruleValue   = $style['fill-rule'] ?? ($el->hasAttribute('fill-rule') ? $el->getAttribute('fill-rule') : null);

        return [
            'fill'        => $fillValue !== null ? $this->parseColor($fillValue) : $inherited['fill'],
            'stroke'      => $strokeValue !== null ? $this->parseColor($strokeValue) : $inherited['stroke'],
            'strokeWidth' => $widthValue !== null && is_numeric($widthValue) ? (float) $widthValue : $inherited['strokeWidth'],
            'evenOdd'     => $ruleValue !== null ? strtolower(trim($ruleValue)) === 'evenodd' : $inherited['evenOdd'],
        ];
    }

    /**
     * A hand-rolled `key:value;key2:value2` splitter for the inline
     * `style=""` attribute — not real CSS (no selectors, no cascade,
     * no `<style>`/class support, which are explicitly out of scope),
     * just the same shorthand real-world tools (Inkscape, some
     * Illustrator exports) commonly use for the handful of presentation
     * properties this renderer already understands via plain attributes.
     *
     * @return array<string, string>
     */
    private function inlineStyle(DOMElement $el): array
    {
        $raw = trim($el->getAttribute('style'));
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (explode(';', $raw) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $out[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return $out;
    }

    /** @return ?array{float, float, float} null means "none" */
    private function parseColor(string $value): ?array
    {
        $value = trim($value);

        if ($value === '' || strtolower($value) === 'none' || strtolower($value) === 'transparent') {
            return null;
        }

        if (str_starts_with($value, 'url(')) {
            throw new InvalidArgumentException('Gradient/pattern fills and strokes are not supported in SVG images.');
        }

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $m) === 1) {
            $r = hexdec($m[1][0] . $m[1][0]);
            $g = hexdec($m[1][1] . $m[1][1]);
            $b = hexdec($m[1][2] . $m[1][2]);

            return [$r / 255.0, $g / 255.0, $b / 255.0];
        }

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $m) === 1) {
            $r = hexdec(substr($m[1], 0, 2));
            $g = hexdec(substr($m[1], 2, 2));
            $b = hexdec(substr($m[1], 4, 2));

            return [$r / 255.0, $g / 255.0, $b / 255.0];
        }

        if (preg_match('/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/i', $value, $m) === 1) {
            return [(float) $m[1] / 255.0, (float) $m[2] / 255.0, (float) $m[3] / 255.0];
        }

        $named = self::NAMED_COLORS[strtolower($value)] ?? null;
        if ($named !== null) {
            return [$named[0] / 255.0, $named[1] / 255.0, $named[2] / 255.0];
        }

        throw new InvalidArgumentException("Unrecognized SVG color value: \"{$value}\".");
    }

    /**
     * Splits a `transform="fn(...) fn(...)"` attribute into its
     * individual function matrices, left-to-right in source order —
     * per spec, a multi-function transform attribute is exactly
     * equivalent to nesting one `<g transform="fn(...)">` per function,
     * so callers nest one `q ... cm`/`Q` pair per matrix returned here
     * rather than pre-multiplying them by hand.
     *
     * @return list<array{float, float, float, float, float, float}>
     */
    private function parseTransformList(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        preg_match_all('/([a-zA-Z]+)\s*\(([^)]*)\)/', $value, $matches, PREG_SET_ORDER);

        $result = [];
        foreach ($matches as $match) {
            $name     = strtolower($match[1]);
            $split    = preg_split('/[\s,]+/', trim($match[2]));
            $rawArgs  = $split !== false ? $split : [];
            $nonEmpty = array_values(array_filter($rawArgs, static fn(string $s): bool => $s !== ''));
            $args     = array_map(static fn(string $s): float => (float) trim($s), $nonEmpty);

            $result[] = $this->transformMatrix($name, $args);
        }

        return $result;
    }

    /**
     * @param  list<float>  $args
     * @return array{float, float, float, float, float, float}
     */
    private function transformMatrix(string $name, array $args): array
    {
        return match ($name) {
            'translate' => [1.0, 0.0, 0.0, 1.0, $args[0] ?? 0.0, $args[1] ?? 0.0],
            'scale'     => [$args[0] ?? 1.0, 0.0, 0.0, $args[1] ?? ($args[0] ?? 1.0), 0.0, 0.0],
            'matrix'    => [$args[0] ?? 1.0, $args[1] ?? 0.0, $args[2] ?? 0.0, $args[3] ?? 1.0, $args[4] ?? 0.0, $args[5] ?? 0.0],
            'rotate'    => $this->rotateMatrix($args),
            'skewx'     => [1.0, 0.0, tan(($args[0] ?? 0.0) * M_PI / 180.0), 1.0, 0.0, 0.0],
            'skewy'     => [1.0, tan(($args[0] ?? 0.0) * M_PI / 180.0), 0.0, 1.0, 0.0, 0.0],
            default     => throw new InvalidArgumentException("Unsupported SVG transform function: \"{$name}\"."),
        };
    }

    /**
     * @param  list<float>  $args
     * @return array{float, float, float, float, float, float}
     */
    private function rotateMatrix(array $args): array
    {
        $angle = ($args[0] ?? 0.0) * M_PI / 180.0;
        $cos   = cos($angle);
        $sin   = sin($angle);

        if (!isset($args[1], $args[2])) {
            return [$cos, $sin, -$sin, $cos, 0.0, 0.0];
        }

        // rotate(a, cx, cy) === translate(cx,cy) rotate(a) translate(-cx,-cy),
        // combined here into one matrix since it's the same 3-function
        // composition either way (the caller still gets one nested q/cm).
        $cx = $args[1];
        $cy = $args[2];

        return [$cos, $sin, -$sin, $cos, $cx - $cos * $cx + $sin * $cy, $cy - $sin * $cx - $cos * $cy];
    }

    /** @param PaintContext $context */
    private function drawRect(DOMElement $el, array $context): string
    {
        $x  = $this->numericAttr($el, 'x');
        $y  = $this->numericAttr($el, 'y');
        $w  = $this->numericAttr($el, 'width');
        $h  = $this->numericAttr($el, 'height');
        $rx = $this->numericAttr($el, 'rx');
        $ry = $el->hasAttribute('ry') ? $this->numericAttr($el, 'ry') : $rx;
        $rx = $el->hasAttribute('rx') ? $rx : $ry;

        if ($rx <= 0.0 || $ry <= 0.0) {
            $paint = $this->paintOperator($context);
            if ($paint === null) {
                return '';
            }

            $strokeWidthOp = $context['strokeWidth'] !== 1.0 ? sprintf("%s w\n", self::number($context['strokeWidth'])) : '';

            return sprintf("%s%s %s %s %s re %s\n", $strokeWidthOp, self::number($x), self::number($y), self::number($w), self::number($h), $paint);
        }

        // Rounded corners: 4 short cubic Bézier arcs (the same
        // kappa-derived corner every rounded-rect renderer uses),
        // built as one closed subpath.
        $k       = 0.5522847498 ;
        $subpath = [
            'start'    => [$x + $rx, $y],
            'segments' => [
                ['l', $x + $w - $rx, $y],
                ['c', $x + $w - $rx + $k * $rx, $y, $x + $w, $y + $ry - $k * $ry, $x + $w, $y + $ry],
                ['l', $x + $w, $y + $h - $ry],
                ['c', $x + $w, $y + $h - $ry + $k * $ry, $x + $w - $rx + $k * $rx, $y + $h, $x + $w - $rx, $y + $h],
                ['l', $x + $rx, $y + $h],
                ['c', $x + $rx - $k * $rx, $y + $h, $x, $y + $h - $ry + $k * $ry, $x, $y + $h - $ry],
                ['l', $x, $y + $ry],
                ['c', $x, $y + $ry - $k * $ry, $x + $rx - $k * $rx, $y, $x + $rx, $y],
            ],
            'closed' => true,
        ];

        return $this->drawSubpaths([$subpath], $context);
    }

    /** @param PaintContext $context */
    private function drawEllipse(float $cx, float $cy, float $rx, float $ry, array $context): string
    {
        $k = 0.5522847498;

        $subpath = [
            'start'    => [$cx + $rx, $cy],
            'segments' => [
                ['c', $cx + $rx, $cy + $k * $ry, $cx + $k * $rx, $cy + $ry, $cx, $cy + $ry],
                ['c', $cx - $k * $rx, $cy + $ry, $cx - $rx, $cy + $k * $ry, $cx - $rx, $cy],
                ['c', $cx - $rx, $cy - $k * $ry, $cx - $k * $rx, $cy - $ry, $cx, $cy - $ry],
                ['c', $cx + $k * $rx, $cy - $ry, $cx + $rx, $cy - $k * $ry, $cx + $rx, $cy],
            ],
            'closed' => true,
        ];

        return $this->drawSubpaths([$subpath], $context);
    }

    /** @return array{start: array{float, float}, segments: list<array{'l', float, float}>, closed: bool} */
    private function pointsToSubpath(DOMElement $el, bool $closed): array
    {
        $raw = trim($el->getAttribute('points'));
        preg_match_all('/-?[\d.]+(?:[eE][-+]?\d+)?/', $raw, $matches);
        $numbers = array_map(static fn(string $s): float => (float) $s, $matches[0]);

        if (count($numbers) < 2) {
            throw new InvalidArgumentException('SVG polyline/polygon requires at least one point.');
        }

        $segments = [];
        $counter  = count($numbers);
        for ($i = 2; $i + 1 < $counter; $i += 2) {
            $segments[] = ['l', $numbers[$i], $numbers[$i + 1]];
        }

        return ['start' => [$numbers[0], $numbers[1]], 'segments' => $segments, 'closed' => $closed];
    }

    /**
     * @param  list<Subpath>  $subpaths
     * @param  PaintContext  $context
     */
    private function drawSubpaths(array $subpaths, array $context): string
    {
        $paint = $this->paintOperator($context);
        if ($paint === null) {
            return '';
        }

        $ops = '';

        if ($context['strokeWidth'] !== 1.0) {
            $ops .= sprintf("%s w\n", self::number($context['strokeWidth']));
        }

        foreach ($subpaths as $subpath) {
            $ops .= sprintf("%s %s m\n", self::number($subpath['start'][0]), self::number($subpath['start'][1]));

            foreach ($subpath['segments'] as $segment) {
                $ops .= $segment[0] === 'l'
                    ? sprintf("%s %s l\n", self::number($segment[1]), self::number($segment[2]))
                    : sprintf("%s %s %s %s %s %s c\n", self::number($segment[1]), self::number($segment[2]), self::number($segment[3]), self::number($segment[4]), self::number($segment[5]), self::number($segment[6]));
            }

            if ($subpath['closed']) {
                $ops .= "h\n";
            }
        }

        return $ops . $paint . "\n";
    }

    /** @param PaintContext $context */
    private function paintOperator(array $context): ?string
    {
        $fill   = $context['fill'];
        $stroke = $context['stroke'];

        if ($fill === null && $stroke === null) {
            return null;
        }

        $colorOps = '';
        if ($fill !== null) {
            $colorOps .= sprintf('%s %s %s rg ', self::number($fill[0]), self::number($fill[1]), self::number($fill[2]));
        }
        if ($stroke !== null) {
            $colorOps .= sprintf('%s %s %s RG ', self::number($stroke[0]), self::number($stroke[1]), self::number($stroke[2]));
        }

        $op = match (true) {
            $fill !== null && $stroke !== null => $context['evenOdd'] ? 'B*' : 'B',
            $fill !== null                     => $context['evenOdd'] ? 'f*' : 'f',
            default                            => 'S',
        };

        return rtrim($colorOps) . "\n" . $op;
    }

    private function numericAttr(DOMElement $el, string $name): float
    {
        $value = trim($el->getAttribute($name));

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private static function number(float $value): string
    {
        return sprintf('%.4F', $value);
    }
}
