<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

use DateTimeImmutable;
use InvalidArgumentException;
use LucianoPereira\Crucible\Reporting\Document\Svg\SvgDocument;

use function array_keys;
use function array_pop;
use function chr;
use function count;
use function end;
use function explode;
use function floor;
use function getimagesizefromstring;
use function max;
use function md5;
use function ord;
use function sprintf;
use function str_contains;
use function str_repeat;
use function str_replace;
use function str_split;
use function strlen;
use function substr;

use const IMAGETYPE_JPEG;

/**
 * Ultra-light single-file PDF writer, ported verbatim from
 * `PdfWriter`'s own primitive methods (D-091's addendum): the PDF 1.4
 * document model (output buffer, object offsets, xref table,
 * trailer), base-14 Helvetica/Courier fonts (metrics are the
 * published AFM widths, nothing embedded), WinAnsi encoding, and the
 * native graphics operators (re, m, l, S, f). Zero report-domain
 * knowledge — everything here is pure PDF mechanics, composed by
 * `PdfRenderer`, never constructed or read anywhere else.
 */
final class PdfPrimitives
{
    // A4 portrait, in points.
    public const float WIDTH  = 595.28;
    public const float HEIGHT = 841.89;
    public const float MARGIN = 56.0;

    public const string HELVETICA = 'F1';
    public const string BOLD      = 'F2';
    public const string COURIER   = 'F3';

    /**
     * Helvetica glyph widths for characters 32–126, in thousandths of
     * the em — the published AFM metrics; base-14 fonts ship no
     * widths of their own.
     */
    private const array HELVETICA_WIDTHS = [
        278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
        1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
        333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
        556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
    ];

    private const array BOLD_WIDTHS = [
        278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
        556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
        975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
        667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
        333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
        611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
    ];

    /** @var list<string> finished pages' content streams */
    public array $pages = [];

    /** the current page's content stream */
    public string $ops = '';

    /** the layout cursor: the top of the next line, from the page bottom */
    public float $y = self::HEIGHT - self::MARGIN;

    /** @var array<string, array{data: string, width: int, height: int, colorSpace: string, invert: bool}> registered images by resource name (Im1, Im2, …), insertion order is object order */
    private array $images = [];

    /** @var array<string, string> content hash → resource name, so the same image embeds once even if drawn on several pages */
    private array $imageNameByHash = [];

    /** @var array<string, true> resource names drawn on the current in-progress page */
    private array $currentPageImages = [];

    /** @var list<array<string, true>> finished pages' image resource names, parallel to $pages */
    private array $pageImages = [];

    /** @var list<array{level: int, title: string, pageIndex: int}> headings recorded as PDF outline (bookmark) entries, in document order */
    private array $bookmarks = [];

    /** @var array<string, array{ops: string, minX: float, minY: float, width: float, height: float}> content hash → parsed SVG, so size()+draw() of the same markup only walks its DOM once */
    private array $svgCache = [];

    /**
     * A section heading: bold with a hairline rule beneath.
     */
    public function heading(string $text): void
    {
        $columns = self::WIDTH - 2 * self::MARGIN;
        $this->ensure(12.0 * 1.4 + 8.0);
        $this->text(self::MARGIN, $this->y - 12.0, self::BOLD, 12.0, $this->encode($text));
        $this->rule(self::MARGIN, $this->y - 16.5, self::MARGIN + $columns, $this->y - 16.5);
        $this->y -= 12.0 * 1.4 + 8.0;
    }

    /**
     * Records a heading as a PDF outline (bookmark) entry, targeting
     * whichever page is currently being built — the page index is
     * already known the instant a heading is drawn, so this needs no
     * lookahead and costs nothing when a document has no headings.
     */
    public function bookmark(int $level, string $title): void
    {
        $this->bookmarks[] = ['level' => $level, 'title' => $title, 'pageIndex' => count($this->pages)];
    }

    /**
     * The headings recorded so far via `bookmark()` — read back by a TOC
     * dry-run pass to learn titles/levels/page numbers before the real
     * pass renders an actual table of contents from them.
     *
     * @return list<array{level: int, title: string, pageIndex: int}>
     */
    public function bookmarkEntries(): array
    {
        return $this->bookmarks;
    }

    /**
     * A dotted leader starting a fixed 8pt after the name it follows,
     * drawn only when there is room for it to read as one.
     */
    public function leader(float $from, float $to, float $baseline): void
    {
        $dot   = $this->measure('.', self::HELVETICA, 8.0);
        $count = (int) floor(($to - $from - 12.0) / $dot);

        if ($count > 2) {
            $this->text($from + 8.0, $baseline, self::HELVETICA, 8.0, str_repeat('.', $count), [0.46, 0.46, 0.46]);
        }
    }

    /**
     * Writes flowing text at the cursor and advances it: one line when it
     * fits, wrapped at word boundaries by flow() when it does not, so a
     * long paragraph stays inside the right margin instead of running
     * off the page.
     *
     * @param ?array{float, float, float} $rgb
     */
    public function line(string $font, float $size, string $text, ?array $rgb = null, float $indent = 0.0): void
    {
        foreach ($this->flow($this->encode($text), self::WIDTH - 2 * self::MARGIN - $indent, $font, $size) as $row) {
            $this->ensure($size * 1.4);
            $this->text(self::MARGIN + $indent, $this->y - $size, $font, $size, $row, $rgb);
            $this->y -= $size * 1.4;
        }
    }

    /**
     * Breaks the page when fewer than $height points remain above the
     * footer. Returns whether it broke.
     */
    public function ensure(float $height): bool
    {
        if ($this->y - $height >= self::MARGIN + 20.0) {
            return false;
        }

        $this->pages[]           = $this->ops;
        $this->pageImages[]      = $this->currentPageImages;
        $this->ops               = '';
        $this->currentPageImages = [];
        $this->y                 = self::HEIGHT - self::MARGIN;

        return true;
    }

    /**
     * Draws a JPEG at the given box — no re-encoding: the file's own
     * bytes become the XObject stream verbatim (`/Filter /DCTDecode`),
     * the trick every PDF/JPEG embedder from FPDF onward relies on. The
     * same bytes drawn more than once (e.g. a repeated cover) still
     * embed only once, keyed by content hash.
     */
    public function image(string $jpegBytes, float $x, float $y, float $width, float $height): void
    {
        $name = $this->registerImage($jpegBytes);

        $this->ops .= sprintf(
            "q %s 0 0 %s %s %s cm /%s Do Q\n",
            $this->number($width),
            $this->number($height),
            $this->number($x),
            $this->number($y),
            $name,
        );

        $this->currentPageImages[$name] = true;
    }

    /**
     * A JPEG's natural pixel dimensions — callers use this to size the
     * placed box (e.g. fit-to-width, preserving aspect ratio) before
     * calling `image()`, which needs the box already decided.
     *
     * @return array{int, int}
     */
    public function imageSize(string $jpegBytes): array
    {
        [$width, $height] = $this->jpegInfo($jpegBytes);

        return [$width, $height];
    }

    /**
     * Draws an SVG at the given box, mapping its own viewBox coordinate
     * space (y-down) into the placed box (y-up, in points) with one
     * outer `cm` — the same technique `image()` uses for a JPEG's unit
     * square, computed by hand here since vector shapes have no
     * XObject convention to lean on. Every shape then draws as plain
     * content-stream operators, nested inside that one `q`/`Q` pair —
     * no `/Resources`/`XObject` entry at all, unlike `image()`.
     */
    public function svg(string $markup, float $x, float $y, float $width, float $height): void
    {
        $rendered = $this->renderedSvg($markup);

        $scaleX = $width / $rendered['width'];
        $scaleY = $height / $rendered['height'];

        $this->ops .= sprintf(
            "q %s %s %s %s %s %s cm\n%sQ\n",
            $this->number($scaleX),
            $this->number(0.0),
            $this->number(0.0),
            $this->number(-$scaleY),
            $this->number($x - $rendered['minX'] * $scaleX),
            $this->number($y + $height + $rendered['minY'] * $scaleY),
            $rendered['ops'],
        );
    }

    /**
     * An SVG's own natural aspect ratio (its viewBox, or width/height
     * attributes when there's no viewBox) — callers use this to size
     * the placed box (fit-to-width, preserving aspect ratio) before
     * calling `svg()`, which needs the box already decided.
     *
     * @return array{float, float}
     */
    public function svgSize(string $markup): array
    {
        $rendered = $this->renderedSvg($markup);

        return [$rendered['width'], $rendered['height']];
    }

    /** @return array{ops: string, minX: float, minY: float, width: float, height: float} */
    private function renderedSvg(string $markup): array
    {
        $hash = md5($markup);

        return $this->svgCache[$hash] ??= (new SvgDocument())->render($markup);
    }

    private function registerImage(string $jpegBytes): string
    {
        $hash = md5($jpegBytes);

        if (isset($this->imageNameByHash[$hash])) {
            return $this->imageNameByHash[$hash];
        }

        [$width, $height, $colorSpace, $invert] = $this->jpegInfo($jpegBytes);
        $name                                   = 'Im' . (count($this->images) + 1);

        $this->images[$name] = [
            'data'       => $jpegBytes,
            'width'      => $width,
            'height'     => $height,
            'colorSpace' => $colorSpace,
            'invert'     => $invert,
        ];

        $this->imageNameByHash[$hash] = $name;

        return $name;
    }

    /**
     * Width, height, and PDF color space straight from the JPEG's own
     * headers — no hand-rolled marker walk needed, `getimagesizefromstring`
     * already reports the component count. The one thing it doesn't
     * expose is Adobe's inverted-CMYK convention (`APP14` present on a
     * 4-component JPEG), which every CMYK JPEG out of Adobe tooling
     * uses — so `/Decode [1 0 1 0 1 0 1 0]` is applied whenever that
     * marker is present, matching FPDF/mPDF's own long-standing rule.
     *
     * @return array{int, int, string, bool}
     */
    private function jpegInfo(string $jpegBytes): array
    {
        $info = @getimagesizefromstring($jpegBytes);

        if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
            throw new InvalidArgumentException('Image data is not a JPEG.');
        }

        $components = $info['channels'] ?? 3;
        $colorSpace = match ($components) {
            1       => 'DeviceGray',
            4       => 'DeviceCMYK',
            default => 'DeviceRGB',
        };

        $invert = $components === 4 && str_contains($jpegBytes, 'Adobe');

        return [$info[0], $info[1], $colorSpace, $invert];
    }

    /**
     * The PDF `/Outlines` bookmark tree, built from the flat `$bookmarks`
     * list by walking it once with a level-keyed stack: an entry's parent
     * is the nearest earlier entry with a strictly lower level (or the
     * synthetic root when none exists), the same convention every
     * heading-outline in Markdown/AsciiDoc/etc. already uses. A second
     * pass groups entries by parent to derive `/Prev`/`/Next`/`/First`/
     * `/Last`/`/Count`, since siblings aren't contiguous once a parent
     * has grandchildren interleaved in document order.
     *
     * @return array<int, string> object id => object body, including the
     *                            root `/Type /Outlines` object at $rootId
     */
    private function outlineObjects(int $rootId): array
    {
        if ($this->bookmarks === []) {
            return [];
        }

        $ids = [];

        foreach (array_keys($this->bookmarks) as $index) {
            $ids[$index] = $rootId + 1 + $index;
        }

        /** @var array<int, int> $parentOf entry index => parent object id (rootId or another entry's id) */
        $parentOf = [];
        /** @var list<array{int, int}> $stack open ancestors as [level, objectId] */
        $stack = [];

        foreach ($this->bookmarks as $index => $entry) {
            while ($stack !== [] && end($stack)[0] >= $entry['level']) {
                array_pop($stack);
            }

            $parentOf[$index] = $stack === [] ? $rootId : end($stack)[1];
            $stack[]          = [$entry['level'], $ids[$index]];
        }

        /** @var array<int, list<int>> $childrenOf parent object id => list of entry indices, in document order */
        $childrenOf = [];
        /** @var array<int, int> $positionOf entry index => its position among its siblings */
        $positionOf = [];

        foreach (array_keys($this->bookmarks) as $index) {
            $parent                = $parentOf[$index];
            $childrenOf[$parent][] = $index;
            $positionOf[$index]    = count($childrenOf[$parent]) - 1;
        }

        $rootChildren = $childrenOf[$rootId] ?? [];
        $objects      = [
            $rootId => sprintf(
                '<< /Type /Outlines /First %d 0 R /Last %d 0 R /Count %d >>',
                $ids[$rootChildren[0]],
                $ids[$rootChildren[count($rootChildren) - 1]],
                count($this->bookmarks),
            ),
        ];

        foreach ($this->bookmarks as $index => $entry) {
            $siblings = $childrenOf[$parentOf[$index]];
            $position = $positionOf[$index];
            $kids     = $childrenOf[$ids[$index]] ?? [];

            $object = sprintf(
                '<< /Title (%s) /Parent %d 0 R',
                $this->escape($this->encode($entry['title'])),
                $parentOf[$index],
            );

            if ($position > 0) {
                $object .= sprintf(' /Prev %d 0 R', $ids[$siblings[$position - 1]]);
            }

            if ($position < count($siblings) - 1) {
                $object .= sprintf(' /Next %d 0 R', $ids[$siblings[$position + 1]]);
            }

            if ($kids !== []) {
                $object .= sprintf(
                    ' /First %d 0 R /Last %d 0 R /Count %d',
                    $ids[$kids[0]],
                    $ids[$kids[count($kids) - 1]],
                    count($kids),
                );
            }

            $object .= sprintf(' /Dest [%d 0 R /XYZ null null null] >>', 7 + 2 * $entry['pageIndex']);

            $objects[$ids[$index]] = $object;
        }

        return $objects;
    }

    // -- Content-stream operators ----------------------------------

    /**
     * @param ?array{float, float, float} $rgb fill color; black when null
     */
    public function text(float $x, float $baseline, string $font, float $size, string $latin1, ?array $rgb = null): void
    {
        [$red, $green, $blue] = $rgb ?? [0.0, 0.0, 0.0];

        $this->ops .= sprintf(
            "BT %s %s %s rg /%s %s Tf %s %s Td (%s) Tj ET\n",
            $this->number($red),
            $this->number($green),
            $this->number($blue),
            $font,
            $this->number($size),
            $this->number($x),
            $this->number($baseline),
            $this->escape($latin1),
        );
    }

    /**
     * Escapes the PDF literal-string delimiters.
     */
    public function escape(string $latin1): string
    {
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $latin1);
    }

    public function rule(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->ops .= sprintf(
            "0.5 w 0.75 G %s %s m %s %s l S\n",
            $this->number($x1),
            $this->number($y1),
            $this->number($x2),
            $this->number($y2),
        );
    }

    /**
     * A filled circle around ($x, $y), four Bézier arcs — the tile
     * dot, drawn as a vector instead of leaning on a font's bullet
     * glyph.
     *
     * @param array{float, float, float} $rgb
     */
    public function disc(float $x, float $y, float $radius, array $rgb): void
    {
        [$red, $green, $blue] = $rgb;
        $k                    = 0.5523 * $radius;

        $this->ops .= sprintf(
            "%s %s %s rg %s %s m %s %s %s %s %s %s c %s %s %s %s %s %s c %s %s %s %s %s %s c %s %s %s %s %s %s c f\n",
            $this->number($red),
            $this->number($green),
            $this->number($blue),
            $this->number($x + $radius),
            $this->number($y),
            $this->number($x + $radius),
            $this->number($y + $k),
            $this->number($x + $k),
            $this->number($y + $radius),
            $this->number($x),
            $this->number($y + $radius),
            $this->number($x - $k),
            $this->number($y + $radius),
            $this->number($x - $radius),
            $this->number($y + $k),
            $this->number($x - $radius),
            $this->number($y),
            $this->number($x - $radius),
            $this->number($y - $k),
            $this->number($x - $k),
            $this->number($y - $radius),
            $this->number($x),
            $this->number($y - $radius),
            $this->number($x + $k),
            $this->number($y - $radius),
            $this->number($x + $radius),
            $this->number($y - $k),
            $this->number($x + $radius),
            $this->number($y),
        );
    }

    /**
     * A filled rectangle; $y is its bottom edge.
     *
     * @param array{float, float, float} $rgb
     */
    public function box(float $x, float $y, float $width, float $height, array $rgb): void
    {
        [$red, $green, $blue] = $rgb;

        $this->ops .= sprintf(
            "%s %s %s rg %s %s %s %s re f\n",
            $this->number($red),
            $this->number($green),
            $this->number($blue),
            $this->number($x),
            $this->number($y),
            $this->number($width),
            $this->number($height),
        );
    }

    public function number(float $value): string
    {
        return sprintf('%.2f', $value);
    }

    // -- Text metrics and encoding ---------------------------------

    /**
     * UTF-8 to the fonts' WinAnsi encoding: Latin-1 passes through,
     * the common typographic points map to their Windows-1252 slots,
     * anything else degrades to '?'. Decoded by hand — no ext-iconv,
     * no ext-mbstring.
     */
    public function encode(string $utf8): string
    {
        $out    = '';
        $length = strlen($utf8);

        for ($i = 0; $i < $length; $i++) {
            $byte = ord($utf8[$i]);

            if ($byte < 0x80) {
                $code = $byte;
            } elseif (($byte & 0xE0) === 0xC0) {
                $code = (($byte & 0x1F) << 6) | (ord(($utf8[++$i] ?? "\0")[0]) & 0x3F);
            } elseif (($byte & 0xF0) === 0xE0) {
                $code = (($byte & 0x0F) << 12) | ((ord(($utf8[++$i] ?? "\0")[0]) & 0x3F) << 6) | (ord(($utf8[++$i] ?? "\0")[0]) & 0x3F);
            } else {
                $code = 0x10000;
                $i += 3;
            }

            $out .= match (true) {
                $code === 0x2018 => chr(0x91),
                $code === 0x2019 => chr(0x92),
                $code === 0x201C => chr(0x93),
                $code === 0x201D => chr(0x94),
                $code === 0x2013 => chr(0x96),
                $code === 0x2014 => chr(0x97),
                $code === 0x2026 => chr(0x85),
                $code < 0x20     => ' ',
                $code > 0xFF     => '?',
                default          => chr($code),
            };
        }

        return $out;
    }

    /**
     * The rendered width of already-encoded text, in points.
     */
    public function measure(string $latin1, string $font, float $size): float
    {
        if ($font === self::COURIER) {
            return strlen($latin1) * 600 * $size / 1000.0;
        }

        $widths  = $font === self::BOLD ? self::BOLD_WIDTHS : self::HELVETICA_WIDTHS;
        $default = $font === self::BOLD ? 611 : 556;
        $units   = 0;

        foreach (str_split($latin1) as $character) {
            $code = ord($character[0]);
            $units += $widths[$code - 32] ?? $default;
        }

        return $units * $size / 1000.0;
    }

    /**
     * Splits already-encoded text into lines that fit — the report
     * never abbreviates with an ellipsis; names wrap instead. An
     * overlong single word hard-splits.
     *
     * @return non-empty-list<string>
     */
    public function flow(string $latin1, float $width, string $font, float $size): array
    {
        $lines   = [];
        $current = '';

        foreach (explode(' ', $latin1) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;

            if ($this->measure($candidate, $font, $size) <= $width) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
            }

            while ($this->measure($word, $font, $size) > $width) {
                $kept = '';

                foreach (str_split($word) as $character) {
                    if ($kept !== '' && $this->measure($kept . $character, $font, $size) > $width) {
                        break;
                    }

                    $kept .= $character;
                }

                $lines[] = $kept;
                $word    = substr($word, strlen($kept));
            }

            $current = $word;
        }

        $lines[] = $current;

        return $lines;
    }

    /**
     * Failure text as encoded Courier lines: newlines respected, long
     * lines chunked at the fixed-pitch column budget.
     *
     * @return list<string>
     */
    public function wrap(string $utf8, float $size, float $width): array
    {
        $columns = max(1, (int) floor($width / (600 * $size / 1000.0)));
        $rows    = [];

        foreach (explode("\n", str_replace("\r\n", "\n", $utf8)) as $line) {
            $encoded = $this->encode($line);

            foreach ($encoded === '' ? [''] : str_split($encoded, $columns) as $chunk) {
                $rows[] = $chunk;
            }
        }

        return $rows;
    }

    // -- The document ----------------------------------------------

    /**
     * The PDF file: header, objects, cross-reference table, trailer.
     * Objects 1–6 are fixed (catalog, page tree, three fonts, info);
     * each page then contributes a page object and a content stream.
     * Finalizes the last in-progress page itself, so a caller never
     * has to touch `$pages`/`$ops` directly.
     */
    public function document(string $title, string $author, string $producer, ?DateTimeImmutable $createdAt): string
    {
        $this->pages[]      = $this->ops;
        $this->pageImages[] = $this->currentPageImages;

        $count = count($this->pages);
        $kids  = '';

        for ($i = 0; $i < $count; $i++) {
            $kids .= sprintf('%d 0 R ', 7 + 2 * $i);
        }

        // Image XObjects follow every page/content object pair, in
        // registration order — their ids only depend on $count, known
        // up front, so pages can reference them within the same loop
        // that builds the page objects below.
        $imageBaseId   = 7 + 2 * $count;
        $imageIdByName = [];

        foreach (array_keys($this->images) as $index => $name) {
            $imageIdByName[$name] = $imageBaseId + $index;
        }

        // The outline (bookmark) tree follows every image XObject, its
        // own id block sized by $bookmarks alone — known up front, same
        // reasoning as $imageBaseId above.
        $outlineRootId  = $imageBaseId + count($this->images);
        $outlineObjects = $this->outlineObjects($outlineRootId);

        // Archival metadata, all derived from the configuration and
        // the event stream — never from the wall clock, so the same
        // run still produces byte-identical output.
        $info = sprintf(
            '<< /Title (%s) /Author (%s) /Producer (%s)',
            $this->escape($this->encode($title)),
            $this->escape($this->encode($author)),
            $producer,
        );

        if ($createdAt instanceof DateTimeImmutable) {
            $info .= sprintf(
                " /CreationDate (D:%s%s')",
                $createdAt->format('YmdHis'),
                str_replace(':', "'", $createdAt->format('P')),
            );
        }

        $info .= ' >>';

        $objects = [
            1 => sprintf(
                '<< /Type /Catalog /Pages 2 0 R%s >>',
                $outlineObjects === [] ? '' : sprintf(' /Outlines %d 0 R', $outlineRootId),
            ),
            2 => sprintf('<< /Type /Pages /Kids [ %s] /Count %d >>', $kids, $count),
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>',
            6 => $info,
        ];

        $brand = $this->encode($producer);

        foreach ($this->pages as $i => $content) {
            // The footer sits under a hairline rule, clear of the
            // content area ensure() protects.
            $footer = $this->encode(sprintf('Page %d of %d', $i + 1, $count));
            $stream = $content . sprintf(
                "0.5 w 0.75 G %s 44.00 m %s 44.00 l S\n",
                $this->number(self::MARGIN),
                $this->number(self::WIDTH - self::MARGIN),
            ) . sprintf(
                "BT 0.46 0.46 0.46 rg /F1 8.00 Tf %s 30.00 Td (%s) Tj ET\n",
                $this->number(self::MARGIN),
                $brand,
            ) . sprintf(
                "BT 0.46 0.46 0.46 rg /F1 8.00 Tf %s 30.00 Td (%s) Tj ET\n",
                $this->number(self::WIDTH - self::MARGIN - $this->measure($footer, self::HELVETICA, 8.0)),
                $footer,
            );

            $xobject = '';

            if ($this->pageImages[$i] !== []) {
                $entries = '';

                foreach (array_keys($this->pageImages[$i]) as $name) {
                    $entries .= sprintf('/%s %d 0 R ', $name, $imageIdByName[$name]);
                }

                $xobject = sprintf(' /XObject << %s>>', $entries);
            }

            $objects[7 + 2 * $i] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %s %s] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>%s >> /Contents %d 0 R >>',
                $this->number(self::WIDTH),
                $this->number(self::HEIGHT),
                $xobject,
                8 + 2 * $i,
            );
            $objects[8 + 2 * $i] = sprintf(
                "<< /Length %d >>\nstream\n%sendstream",
                strlen($stream),
                $stream,
            );
        }

        foreach ($this->images as $name => $image) {
            $decode = $image['invert'] ? ' /Decode [1 0 1 0 1 0 1 0]' : '';

            $objects[$imageIdByName[$name]] = sprintf(
                "<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /%s /BitsPerComponent 8 /Filter /DCTDecode%s /Length %d >>\nstream\n%sendstream",
                $image['width'],
                $image['height'],
                $image['colorSpace'],
                $decode,
                strlen($image['data']),
                $image['data'],
            );
        }

        foreach ($outlineObjects as $id => $object) {
            $objects[$id] = $object;
        }

        $body    = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($body);
            $body .= sprintf("%d 0 obj\n%s\nendobj\n", $id, $object);
        }

        $start = strlen($body);
        $body .= sprintf("xref\n0 %d\n0000000000 65535 f \n", count($objects) + 1);

        foreach ($offsets as $offset) {
            $body .= sprintf("%010d 00000 n \n", $offset);
        }

        return $body . sprintf(
            "trailer\n<< /Size %d /Root 1 0 R /Info 6 0 R >>\nstartxref\n%d\n%%%%EOF\n",
            count($objects) + 1,
            $start,
        );
    }
}
