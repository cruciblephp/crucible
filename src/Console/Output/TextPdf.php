<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Console\Output;

use DateTimeImmutable;
use LucianoPereira\Crucible\Reporting\Document\PdfPrimitives;

use function explode;
use function rtrim;
use function str_repeat;

/**
 * A plain-text document as a monospaced PDF.
 *
 * ⚠ Deliberately not a Markdown renderer. `PdfRenderer` lays out the
 * reporting model — headings, tiles, ranked lists — and has no block
 * for a table or a fenced code sample, which is most of what the manual
 * is made of. Converting would drop them silently.
 *
 * So the manual goes through the same pipeline the terminal uses:
 * {@see Markdown::render()} resolves it to laid-out text once, and this
 * puts that text on a page verbatim. What the reader sees on paper is
 * what they would have seen in the pager, which is the whole point.
 */
final readonly class TextPdf
{
    /** Points. Courier at this size fits an 80-column line inside A4's margins. */
    private const float SIZE = 8.5;

    public function __construct(
        private string $producer = 'Crucible',
    ) {}

    public function render(string $text, string $title, string $author, ?DateTimeImmutable $createdAt = null): string
    {
        $pdf = new PdfPrimitives();

        $pdf->heading($title);

        foreach (explode("\n", $text) as $line) {
            // A blank line is still a line: dropping it would close up
            // the spacing the text uses to separate its sections.
            $pdf->line(PdfPrimitives::COURIER, self::SIZE, rtrim($line) === '' ? ' ' : rtrim($line));
        }

        return $pdf->document($title, $author, $this->producer, $createdAt);
    }

    /** The rule under a heading, for callers assembling several documents. */
    public function divider(int $width): string
    {
        return str_repeat('─', $width);
    }
}
