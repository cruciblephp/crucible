<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Snapshot;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Snapshot\InlineSnapshotWriter;

use function file_get_contents;
use function file_put_contents;
use function is_string;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * The inline-snapshot rewrite engine (D-076): the pure source
 * transformation — locate the call at a captured line, splice the value
 * into its argument — plus the literal formatting (single-quote vs
 * nowdoc). Rewriting a file with no matching call is a byte-exact no-op.
 */
#[CoversClass(InlineSnapshotWriter::class)]
final class InlineSnapshotWriterTest extends TestCase
{
    protected function tearDown(): void
    {
        InlineSnapshotWriter::reset();
    }

    public function testRecordsIntoAnEmptyCall(): void
    {
        $source = <<<'PHP'
            <?php
            expect($x)->toMatchInlineSnapshot();
            PHP;

        $out = InlineSnapshotWriter::rewrite($source, 2, 'the value');

        $this->assertStringContainsString("toMatchInlineSnapshot('the value')", $out);
    }

    public function testReplacesAnExistingLiteral(): void
    {
        $source = <<<'PHP'
            <?php
            expect($x)->toMatchInlineSnapshot('stale');
            PHP;

        $out = InlineSnapshotWriter::rewrite($source, 2, 'fresh');

        $this->assertStringContainsString("toMatchInlineSnapshot('fresh')", $out);
        $this->assertStringNotContainsString('stale', $out);
    }

    public function testThePhpunitSpellingPreservesTheValueAndAppendsTheSnapshot(): void
    {
        // assertMatchesInlineSnapshot($value, $expected) — the value is
        // the first argument and must survive; the snapshot is appended
        // (or replaced) as the second.
        $fresh = InlineSnapshotWriter::rewrite(
            "<?php\n\$this->assertMatchesInlineSnapshot(\$value);\n",
            2,
            'recorded',
        );
        $this->assertStringContainsString("assertMatchesInlineSnapshot(\$value, 'recorded')", $fresh);

        $replaced = InlineSnapshotWriter::rewrite(
            "<?php\n\$this->assertMatchesInlineSnapshot(\$value, 'stale');\n",
            2,
            'recorded',
        );
        $this->assertStringContainsString("assertMatchesInlineSnapshot(\$value, 'recorded')", $replaced);
        $this->assertStringNotContainsString('stale', $replaced);
    }

    public function testAMultiLineValueBecomesANowdoc(): void
    {
        $source = <<<'PHP'
            <?php
            expect($x)->toMatchInlineSnapshot();
            PHP;

        $out = InlineSnapshotWriter::rewrite($source, 2, "line one\nline two");

        $this->assertStringContainsString("<<<'SNAPSHOT'\nline one\nline two\nSNAPSHOT", $out);
    }

    public function testAnUnpreservedChainAfterTheCallSurvives(): void
    {
        $source = <<<'PHP'
            <?php
            expect($x)->toMatchInlineSnapshot()->and($y)->toBe(1);
            PHP;

        $out = InlineSnapshotWriter::rewrite($source, 2, 'v');

        $this->assertStringContainsString("toMatchInlineSnapshot('v')->and(\$y)->toBe(1)", $out);
    }

    public function testNoMatchingCallLeavesTheSourceByteExact(): void
    {
        $source = <<<'PHP'
            <?php
            expect($x)->toBe(1);
            PHP;

        $this->assertSame($source, InlineSnapshotWriter::rewrite($source, 2, 'v'));
    }

    public function testSingleQuoteEscapingRoundTrips(): void
    {
        $this->assertSame("'it\\'s a \\\\ test'", InlineSnapshotWriter::literal("it's a \\ test"));
    }

    public function testTheNowdocMarkerAvoidsCollisionWithContent(): void
    {
        // A value whose own line reads SNAPSHOT would close the nowdoc
        // early; the marker lengthens until it is unambiguous.
        $literal = InlineSnapshotWriter::literal("a\nSNAPSHOT\nb");

        $this->assertStringStartsWith("<<<'SNAPSHOT_'\n", $literal);
        $this->assertStringEndsWith("\nSNAPSHOT_", $literal);
    }

    public function testFlushAppliesEveryQueuedEditBottomUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'crucible-inline-');

        if (!is_string($file)) {
            self::fail('Cannot open a temp file.');
        }

        file_put_contents($file, <<<'PHP'
            <?php
            expect($a)->toMatchInlineSnapshot();
            expect($b)->toMatchInlineSnapshot();
            PHP);

        // The line-2 edit is a multi-line nowdoc — it adds lines. Applied
        // bottom-up, it cannot invalidate the line-3 edit's captured line.
        InlineSnapshotWriter::record($file, 2, "first\nsecond");
        InlineSnapshotWriter::record($file, 3, 'plain');

        $applied = InlineSnapshotWriter::flush();
        $out     = (string) file_get_contents($file);
        unlink($file);

        $this->assertSame(2, $applied);
        $this->assertStringContainsString("toMatchInlineSnapshot(<<<'SNAPSHOT'\nfirst\nsecond\nSNAPSHOT)", $out);
        $this->assertStringContainsString("toMatchInlineSnapshot('plain')", $out);
    }
}
