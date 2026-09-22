<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * How a verdict is classified on the INCUMBENT's side of the probe.
 *
 * This file is never loaded by Crucible. It is `require`d by the suites
 * compare.php and regenerate.php write into the oracle's own tests/, so
 * it runs in the incumbent's process, against the incumbent's engine,
 * and it names `PHPUnit\Framework\ExpectationFailedException` — a class
 * Crucible deliberately does not depend on. The default `composer
 * analyse` therefore excludes it and `composer analyse:oracles` checks
 * it against the real pest-oracle instead, exactly as the Laravel bridge
 * and the duplication check are handled.
 *
 * It is a real file rather than a string the generator emits, and that
 * is the whole point. This classification existed in FIVE copies across
 * the two entry points, every one of them inside a string literal being
 * concatenated into a generated suite — and code inside a string is not
 * code to any gate. Measured: phpcpd finds no clone here at its default
 * settings, before or after the copies were removed, because a
 * tokeniser sees string tokens. So the duplication that produced the
 * false-green bug could not have been caught by pointing a clone
 * detector at this directory; the only fix was to stop writing code as
 * text. Being a file is what lets a type checker read it at all.
 *
 * The counterpart on Crucible's side is compare.php's `crucibleCell()`,
 * and the two must NOT be merged: different process, different engine,
 * different exception type. Sharing them would mean one classifying by
 * a name its own engine never raises, which is how a refusal came to
 * read as a failure in the first place.
 *
 * ⚠ The class caught here is `AssertionFailedError`, the BASE, and that
 * is a correction: it used to be `ExpectationFailedException`, one
 * subclass of it. Every value matcher this probe swept happens to fail
 * through that subclass, so the narrowing cost nothing — until the arch
 * axis, where Pest raises `Pest\Arch\Exceptions\ArchExpectationFailedException`,
 * a SIBLING subclass. ✓ Measured 2026-09-06: a failing `toBeAbstract`
 * was being recorded as `x`, REFUSED, when the incumbent had answered
 * "no" perfectly clearly. Under `->not` that reads as green.
 *
 * The asymmetry is what should have given it away sooner. Crucible's
 * side already caught its own BASE failure class; only the incumbent's
 * side named a subclass. Two probes classifying the same event at
 * different depths cannot agree about it.
 */

/**
 * One cell of the grid: `p` pass, `f` fail, `x` REFUSED.
 *
 * Three states, never two. Catching Throwable and writing `'f'` says
 * "the assertion failed" about a case where nothing was asserted — a
 * wrong-typed subject, a matcher that does not exist, a crash. Under
 * `->not` a failure inverts and a refusal does not, so the two are
 * opposites exactly where it matters, and 373 cells of this grid once
 * hid a false pass behind that single shared character.
 *
 * @param non-empty-string $matcher
 *
 * @return 'p'|'f'|'x'
 */
function cell(mixed $value, string $matcher, bool $negated = false): string
{
    try {
        $negated ? \expect($value)->not->{$matcher}() : \expect($value)->{$matcher}();

        return 'p';
    } catch (PHPUnit\Framework\AssertionFailedError) {
        return 'f';
    } catch (Throwable) {
        return 'x';
    }
}

/**
 * What `->not` must answer, given the positive verdict.
 *
 * A refusal does not invert. This is the relation the record leans on
 * instead of carrying a second grid, and the generated suite re-proves
 * it against the live incumbent on every run rather than assuming it.
 *
 * @param 'p'|'f'|'x' $cell
 *
 * @return 'p'|'f'|'x'
 */
function inverted(string $cell): string
{
    return match ($cell) {
        'p'     => 'f',
        'f'     => 'p',
        default => 'x',
    };
}

/**
 * The same call as a two-state verdict, for the records that hold one.
 *
 * The hand-picked table and the contradiction proofs record only
 * pass/fail, because every subject in them is one the incumbent answers
 * about — so a refusal folding into `'fail'` here changes nothing today.
 * It is spelled out rather than left implicit precisely because that
 * fold is the conflation the grid exists to avoid: [cell] keeps the
 * distinction where it earns its keep.
 *
 * @param non-empty-string $matcher
 *
 * @return 'pass'|'fail'
 */
function verdict(mixed $value, string $matcher): string
{
    return \cell($value, $matcher) === 'p' ? 'pass' : 'fail';
}
