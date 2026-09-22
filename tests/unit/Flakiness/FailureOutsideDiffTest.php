<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Flakiness;

use LucianoPereira\Crucible\Attributes\CoversClass;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Flakiness\FailureOutsideDiff;
use LucianoPereira\Crucible\Framework\TestCase;

#[CoversClass(FailureOutsideDiff::class)]
final class FailureOutsideDiffTest extends TestCase
{
    /**
     * The map every case reads: two test files, three source files.
     * AlphaTest executes A and B; BetaTest executes only C.
     */
    private function map(): TestLineMap
    {
        return new TestLineMap([
            'tests/AlphaTest.php::adds'   => ['src/A.php' => [3, 4], 'src/B.php' => [8]],
            'tests/BetaTest.php::renders' => ['src/C.php' => [12]],
        ]);
    }

    private function check(): FailureOutsideDiff
    {
        return new FailureOutsideDiff($this->map());
    }

    public function testAFailureThatExecutedNoChangedFileIsASuspect(): void
    {
        // A.php changed; BetaTest never executes it — outside the diff.
        self::assertSame(
            ['tests/BetaTest.php::renders'],
            $this->check()->suspects(
                ['tests/AlphaTest.php::adds', 'tests/BetaTest.php::renders'],
                ['src/A.php'],
                ['src/'],
            ),
        );
    }

    public function testAFailureThatExecutedAChangedFileIsNeverASuspect(): void
    {
        self::assertSame(
            [],
            $this->check()->suspects(['tests/AlphaTest.php::adds'], ['src/B.php'], ['src/']),
        );
    }

    public function testAFailingTestWhoseOwnFileChangedIsNeverASuspect(): void
    {
        // BetaTest.php itself changed alongside a source file it does
        // not execute: its own code is a suspect, the hint is not.
        self::assertSame(
            [],
            $this->check()->suspects(
                ['tests/BetaTest.php::renders'],
                ['src/A.php', 'tests/BetaTest.php'],
                ['src/'],
            ),
        );
    }

    public function testAChangedDeclaringFileOfAnotherTestDoesNotSuppressTheCheck(): void
    {
        // AlphaTest.php changed (it declares tests in the map), plus a
        // source file BetaTest never runs — BetaTest stays a suspect.
        self::assertSame(
            ['tests/BetaTest.php::renders'],
            $this->check()->suspects(
                ['tests/BetaTest.php::renders'],
                ['src/A.php', 'tests/AlphaTest.php'],
                ['src/'],
            ),
        );
    }

    public function testUnobservableChangedCodeSilencesTheCheckEntirely(): void
    {
        // bootstrap.php is neither in the coverage scope nor a test
        // declaring file: "executed no changed code" cannot be claimed.
        self::assertSame(
            [],
            $this->check()->suspects(
                ['tests/BetaTest.php::renders'],
                ['src/A.php', 'bootstrap.php'],
                ['src/'],
            ),
        );
    }

    public function testATestOnlyDiffProducesNoHint(): void
    {
        // Without an in-scope change there is no diff to be outside of.
        self::assertSame(
            [],
            $this->check()->suspects(
                ['tests/BetaTest.php::renders'],
                ['tests/AlphaTest.php'],
                ['src/'],
            ),
        );
    }

    public function testAFailureUnknownToTheMapIsSkipped(): void
    {
        self::assertSame(
            [],
            $this->check()->suspects(['tests/NewTest.php::fresh'], ['src/A.php'], ['src/']),
        );
    }

    public function testEmptyInputsAreSilence(): void
    {
        self::assertSame([], $this->check()->suspects([], ['src/A.php'], ['src/']));
        self::assertSame([], $this->check()->suspects(['tests/BetaTest.php::renders'], [], ['src/']));
        self::assertSame([], $this->check()->suspects(['tests/BetaTest.php::renders'], ['src/A.php'], []));
    }

    public function testAWholeFileScopeEntryMatchesExactly(): void
    {
        // source(include:) can name single files: an exact name is in
        // scope, and 'src/A.php' must not claim 'src/A.php.bak' — the
        // .bak twin is unobservable code, which silences the check.
        $check = new FailureOutsideDiff(new TestLineMap([
            'tests/BetaTest.php::renders' => ['src/C.php' => [12]],
        ]));

        self::assertSame(
            ['tests/BetaTest.php::renders'],
            $check->suspects(['tests/BetaTest.php::renders'], ['src/A.php'], ['src/A.php', 'src/C.php']),
        );
        self::assertSame(
            [],
            $check->suspects(['tests/BetaTest.php::renders'], ['src/A.php.bak.php'], ['src/A.php', 'src/C.php']),
        );
    }
}
