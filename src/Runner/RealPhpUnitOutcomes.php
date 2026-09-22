<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use Throwable;

/**
 * Real PHPUnit's own markTestSkipped()/markTestIncomplete()
 * (PHPUnit\Framework\Assert, inherited by any real PHPUnit-descended
 * TestCase) throw PHPUnit\Framework\SkippedWithMessageException /
 * PHPUnit\Framework\IncompleteTestError — genuinely different classes
 * from Crucible's own SkippedTestError/IncompleteTestError, not
 * aliased when phpunit/phpunit is actually installed
 * (PhpUnitCompatibility::load() only aliases when the real package is
 * absent). TestRunner's own outcome classification only checked
 * Crucible's own types, so a real markTestIncomplete() call — from a
 * #[PostCondition]-attributed method mixed into a real
 * PHPUnit-descended uses() class, the case this was found from
 * (Spatie\Snapshots\MatchesSnapshots — verified against a real
 * phpunit/phpunit run first) — was classified as an Error, not
 * Incomplete.
 *
 * Checked via the marker interfaces real PHPUnit itself defines for
 * exactly this purpose (PHPUnit\Framework\SkippedTest/IncompleteTest,
 * both `extends Throwable`, implemented by every concrete
 * skip/incomplete exception PHPUnit throws) rather than the two
 * concrete classes by name — the interface is what real PHPUnit's own
 * code checks against, so this stays correct even if a future PHPUnit
 * version renames or adds another concrete implementation.
 *
 * isFailure() closes the same gap for assertion failures: a real,
 * un-aliased PHPUnit-descended TestCase's own inherited assertSame()
 * (etc.) throws PHPUnit\Framework\ExpectationFailedException, which
 * extends PHPUnit\Framework\AssertionFailedError — genuinely distinct
 * from Crucible's own AssertionFailedError, so TestRunner's outcome
 * classification fell through to its default (Errored) branch instead
 * of Failed. Verified with a deliberate real-assertSame mismatch
 * first: real PHPUnit itself reports a failure, not an error.
 *
 * Isolated in its own file, excluded from phpstan (phpstan.neon), for
 * the same reason as RealPhpUnitHookAttributes: compiles against
 * PHPUnit\Framework\* classes deliberately not a dev-dependency of
 * the engine.
 */
final class RealPhpUnitOutcomes
{
    public static function isSkipped(Throwable $thrown): bool
    {
        return $thrown instanceof \PHPUnit\Framework\SkippedTest;
    }

    public static function isIncomplete(Throwable $thrown): bool
    {
        return $thrown instanceof \PHPUnit\Framework\IncompleteTest;
    }

    public static function isFailure(Throwable $thrown): bool
    {
        return $thrown instanceof \PHPUnit\Framework\AssertionFailedError;
    }
}
