<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\Document\Blocks;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Framework\TestCase;
use LucianoPereira\Crucible\Reporting\Document\Blocks\FoldingTree;
use LucianoPereira\Crucible\Test\TestId;

use function array_keys;

/**
 * Tests the tree-construction and folding logic directly, independent
 * of any renderer — previously only reachable via byte-level PDF
 * assertions in `ReportersTest.php` (D-091's addendum: the concrete
 * testability win of separating content from rendering).
 */
#[CoversClass(FoldingTree::class)]
final class FoldingTreeTest extends TestCase
{
    /**
     * @param non-empty-string $file
     * @param non-empty-string $name
     */
    private function passed(string $file, string $name, float $duration): TestFinished
    {
        return new TestFinished(new TestId($file, $name), Outcome::Passed, $duration);
    }

    public function testSingleChildChainsCollapseIntoOneLabel(): void
    {
        $sections = [
            'tests/unit/Foo/BarTest.php' => [$this->passed('tests/unit/Foo/BarTest.php', 'testOne', 0.010)],
        ];

        $tree = FoldingTree::build($sections, 0.010);

        $this->assertSame(['tests/unit/Foo'], array_keys($tree->visible));
    }

    public function testAbsolutePathsFoldInsteadOfRecursingForever(): void
    {
        // Running from a directory that is not an ancestor of the tests
        // leaves every path absolute, whose first segment is empty.
        $sections = [
            '/srv/app/tests/unit/OneTest.php' => [$this->passed('/srv/app/tests/unit/OneTest.php', 'testOne', 0.010)],
        ];

        $tree = FoldingTree::build($sections, 0.010);

        $this->assertSame(['/srv/app/tests/unit'], array_keys($tree->visible));
    }

    public function testAbsoluteSiblingsStillSplitAtTheirCommonRoot(): void
    {
        $sections = [
            '/srv/app/a/OneTest.php' => [$this->passed('/srv/app/a/OneTest.php', 'testSlow', 0.030)],
            '/srv/app/b/TwoTest.php' => [$this->passed('/srv/app/b/TwoTest.php', 'testFast', 0.001)],
        ];

        $tree = FoldingTree::build($sections, 0.031);

        $this->assertSame(['/srv/app'], array_keys($tree->visible));
        $this->assertSame(['a', 'b'], array_keys($tree->visible['/srv/app']->children));
    }

    public function testSiblingsOrderByDescendingSubtreeTime(): void
    {
        $sections = [
            'a/OneTest.php' => [$this->passed('a/OneTest.php', 'testSlow', 0.030)],
            'b/TwoTest.php' => [$this->passed('b/TwoTest.php', 'testFast', 0.001)],
        ];

        $tree = FoldingTree::build($sections, 0.031);

        $this->assertSame(['a', 'b'], array_keys($tree->visible));
    }

    public function testSubThresholdTailFolds(): void
    {
        // 'slow' is well above the 1ms/0.1% floor; 'tiny'/'wee' are a
        // microsecond each, far under both — both fold (a fold of
        // exactly one is pointless and un-folds instead, tested
        // separately below, so this needs at least two).
        $sections = [
            'slow/OneTest.php'  => [$this->passed('slow/OneTest.php', 'testSlow', 1.0)],
            'tiny/TwoTest.php'  => [$this->passed('tiny/TwoTest.php', 'testTiny', 0.000001)],
            'wee/ThreeTest.php' => [$this->passed('wee/ThreeTest.php', 'testWee', 0.000001)],
        ];

        $tree = FoldingTree::build($sections, 1.000002);

        $this->assertSame(['slow'], array_keys($tree->visible));
        $this->assertSame(2, $tree->foldedDirectoryCount);
        $this->assertSame(2, $tree->foldedTestCount);
    }

    public function testAFoldOfExactlyOneStaysVisible(): void
    {
        // Only one directory would fold — not worth a "+1 more" line,
        // so it stays visible instead.
        $sections = [
            'slow/OneTest.php' => [$this->passed('slow/OneTest.php', 'testSlow', 1.0)],
            'tiny/TwoTest.php' => [$this->passed('tiny/TwoTest.php', 'testTiny', 0.000001)],
        ];

        $tree = FoldingTree::build($sections, 1.000001);

        $this->assertSame(0, $tree->foldedDirectoryCount);
        $this->assertSame(['slow', 'tiny'], array_keys($tree->visible));
    }

    public function testADirectoryHoldingAFlaggedFileNeverFolds(): void
    {
        $flagged = new TestFinished(new TestId('tiny/TwoTest.php', 'testTiny'), Outcome::Failed, 0.000001);

        // 'also' and 'still' are both unprotected sub-threshold
        // directories, so at least two genuinely fold (a fold of
        // exactly one un-folds instead, tested separately above).
        $sections = [
            'slow/OneTest.php'   => [$this->passed('slow/OneTest.php', 'testSlow', 1.0)],
            'tiny/TwoTest.php'   => [$flagged],
            'also/ThreeTest.php' => [$this->passed('also/ThreeTest.php', 'testAlso', 0.000001)],
            'still/FourTest.php' => [$this->passed('still/FourTest.php', 'testStill', 0.000001)],
        ];

        $tree = FoldingTree::build($sections, 1.000003);

        // 'tiny' holds the flagged test and must stay visible even
        // though it's sub-threshold; 'also'/'still' have nothing
        // protecting them and fold.
        $this->assertArrayHasKey('tiny', $tree->visible);
        $this->assertArrayNotHasKey('also', $tree->visible);
        $this->assertArrayNotHasKey('still', $tree->visible);
        $this->assertSame(2, $tree->foldedDirectoryCount);
    }

    public function testSampleReturnsAValidInstance(): void
    {
        $tree = FoldingTree::sample();

        $this->assertNotSame([], $tree->visible);
    }
}
