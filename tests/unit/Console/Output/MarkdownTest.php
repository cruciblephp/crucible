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
use LucianoPereira\Crucible\Console\Output\MarkdownDocument;
use LucianoPereira\Crucible\Framework\TestCase;

use function count;

#[CoversClass(Markdown::class)]
#[CoversClass(MarkdownDocument::class)]
final class MarkdownTest extends TestCase
{
    public function testStripsBoldMarkers(): void
    {
        $document = Markdown::render('**bold** text', 80);

        $this->assertSame('bold text', $document->lines[0]->plainText());
    }

    public function testStripsInlineCodeAndItalics(): void
    {
        $this->assertSame('run x now', Markdown::render('run `x` now', 80)->lines[0]->plainText());
        $this->assertSame('an word here', Markdown::render('an *word* here', 80)->lines[0]->plainText());
    }

    public function testHeadings(): void
    {
        $this->assertSame('Title', Markdown::render('# Title', 80)->lines[0]->plainText());
    }

    public function testBulletList(): void
    {
        $this->assertSame('• item', Markdown::render('- item', 80)->lines[0]->plainText());
    }

    public function testCollectsLinksAsNumberedReferences(): void
    {
        $document = Markdown::render('See [the guide](guide.md) for more.', 80);

        $this->assertSame(['guide.md'], $document->links);
        $this->assertSame('See the guide[1] for more.', $document->lines[0]->plainText());
    }

    public function testExternalAndLocalLinksAreBothCollectedInOrder(): void
    {
        $document = Markdown::render('[a](x.md) then [b](https://e.com)', 80);

        $this->assertSame(['x.md', 'https://e.com'], $document->links);
    }

    public function testWrapsLongParagraphs(): void
    {
        $document = Markdown::render('one two three four five', 9);

        $this->assertGreaterThan(1, count($document->lines));
    }

    /**
     * A bold span that straddles a hard-wrapped source line (as README
     * files routinely have, wrapped at ~80-100 columns by the author)
     * must still be recognised as bold, not left as literal `**`
     * markers either side of the line break.
     */
    public function testBoldSpanCrossingAHardWrappedSourceLineIsStillParsed(): void
    {
        $document = Markdown::render("**one two\nthree four**", 80);

        $this->assertSame('one two three four', $document->plainText());
        $this->assertStringNotContainsString('*', $document->plainText());
    }

    /**
     * A bullet item's continuation line — indented, no `-`/`*` marker,
     * exactly how Markdown authors hard-wrap a long list item — joins
     * the same bullet instead of falling through as its own unindented
     * paragraph.
     */
    public function testBulletContinuationLineJoinsTheSameItem(): void
    {
        $document = Markdown::render("- one two\n  three four\n- second item", 80);

        $this->assertCount(2, $document->lines);
        $this->assertSame('• one two three four', $document->lines[0]->plainText());
        $this->assertSame('• second item', $document->lines[1]->plainText());
    }

    /**
     * A blank line (or any other block) still ends a bullet's
     * continuation — only contiguous indented lines join it.
     */
    public function testBlankLineEndsABulletContinuation(): void
    {
        $document = Markdown::render("- item\n\nnot part of the item", 80);

        $this->assertSame('• item', $document->lines[0]->plainText());
        $this->assertSame('', $document->lines[1]->plainText());
        $this->assertSame('not part of the item', $document->lines[2]->plainText());
    }

    public function testTableRendersAsABorderedGridWithAHeaderRow(): void
    {
        $document = Markdown::render("| A | B |\n|---|---|\n| 1 | two |", 80);

        $text = $document->plainText();

        $this->assertStringContainsString('┌', $text);
        $this->assertStringContainsString('┬', $text);
        $this->assertStringContainsString('└', $text);
        $this->assertStringContainsString('A', $text);
        $this->assertStringContainsString('B', $text);
        $this->assertStringContainsString('1', $text);
        $this->assertStringContainsString('two', $text);
        $this->assertStringNotContainsString('---', $text);
    }

    public function testTableCellsKeepFollowableLinkReferences(): void
    {
        $document = Markdown::render("| Doc |\n|---|\n| [guide](guide.md) |", 80);

        $this->assertSame(['guide.md'], $document->links);
        $this->assertStringContainsString('guide[1]', $document->plainText());
    }
}
