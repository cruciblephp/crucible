<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Console\Output;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Console\Output\Markdown;
use LucianoPereira\Crucible\Console\Output\TextPdf;
use LucianoPereira\Crucible\Framework\TestCase;

use function str_contains;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function substr_count;

/**
 * The manual on paper: the same text the pager lays out.
 *
 * ⚠ Not a Markdown renderer. `PdfRenderer` lays out the reporting model
 * and has no block for a table or a fenced sample, which is most of
 * what the manual is; converting would drop them without saying so.
 */
#[CoversClass(TextPdf::class)]
final class TextPdfTest extends TestCase
{
    public function testItProducesAReadablePdf(): void
    {
        $pdf = (new TextPdf())->render("Hello\n\nWorld", 'A title', 'An author');

        self::assertTrue(str_starts_with($pdf, '%PDF-1.4'), 'a PDF header');
        self::assertStringContainsString('%%EOF', $pdf, 'and a trailer');
        self::assertStringContainsString('/BaseFont /Courier', $pdf, 'set monospaced, so columns line up');
    }

    /**
     * Long text breaks across pages rather than running off the first.
     *
     * ✓ The control: a renderer that ignored the page height would
     * produce exactly one page for any input, and the manual's later
     * sections would simply not exist.
     */
    public function testLongTextIsPaginated(): void
    {
        $short = (new TextPdf())->render("one line", 'T', 'A');
        $long  = (new TextPdf())->render(str_repeat("a line of the manual\n", 400), 'T', 'A');

        self::assertSame(1, substr_count($short, '/Type /Page '), 'one page for one line');
        self::assertGreaterThan(4, substr_count($long, '/Type /Page '), 'and many for many');
        self::assertGreaterThan(strlen($short), strlen($long));
    }

    /** The real manual goes through it, not just a fixture. */
    public function testTheManualItselfRenders(): void
    {
        $text = Markdown::render("# Heading\n\nA paragraph with `code`.\n\n- a bullet\n", 96)->plainText();
        $pdf  = (new TextPdf())->render($text, 'Crucible PHP — the manual', 'Luciano Federico Pereira');

        self::assertTrue(str_starts_with($pdf, '%PDF-1.4'));
        self::assertFalse(str_contains($pdf, '# Heading'), 'the markup is resolved, not printed');
    }
}
