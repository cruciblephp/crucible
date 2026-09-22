<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Assert;

use LucianoPereira\Crucible\Assert\Differ;
use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(Differ::class)]
final class DifferTest extends TestCase
{
    public function testMarksRemovalsAdditionsAndContext(): void
    {
        $diff = Differ::diff("a\nb\nc", "a\nx\nc");

        $this->assertSame(
            "--- Expected\n+++ Actual\n a\n-b\n+x\n c",
            $diff,
        );
    }

    public function testPureAdditionAndRemoval(): void
    {
        $this->assertSame(
            "--- Expected\n+++ Actual\n a\n+b",
            Differ::diff('a', "a\nb"),
        );
        $this->assertSame(
            "--- Expected\n+++ Actual\n a\n-b",
            Differ::diff("a\nb", 'a'),
        );
    }

    public function testContextCollapsesTheUnchangedRunsAroundEachChange(): void
    {
        try {
            Differ::context(1);

            $this->assertSame(
                "--- Expected\n+++ Actual\n @@ 2 unchanged line(s) omitted @@\n c\n-d\n+D\n e\n @@ 2 unchanged line(s) omitted @@",
                Differ::diff("a\nb\nc\nd\ne\nf\ng", "a\nb\nc\nD\ne\nf\ng"),
            );

            // Zero context keeps the changed lines and nothing else.
            Differ::context(0);

            $this->assertSame(
                "--- Expected\n+++ Actual\n @@ 3 unchanged line(s) omitted @@\n-d\n+D\n @@ 3 unchanged line(s) omitted @@",
                Differ::diff("a\nb\nc\nd\ne\nf\ng", "a\nb\nc\nD\ne\nf\ng"),
            );

            // A negative clamps to zero, not to "no limit": zero is itself
            // a real request, so the nonsense value lands on the nearest
            // meaningful one rather than silently turning the limit off.
            Differ::context(-5);

            $this->assertSame(
                "--- Expected\n+++ Actual\n @@ 1 unchanged line(s) omitted @@\n-b\n+x\n @@ 1 unchanged line(s) omitted @@",
                Differ::diff("a\nb\nc", "a\nx\nc"),
            );
        } finally {
            Differ::context(null);
        }
    }

    public function testWithoutContextEveryUnchangedLineSurvives(): void
    {
        $this->assertSame(
            "--- Expected\n+++ Actual\n a\n b\n c\n-d\n+D\n e\n f\n g",
            Differ::diff("a\nb\nc\nd\ne\nf\ng", "a\nb\nc\nD\ne\nf\ng"),
        );
    }
}
