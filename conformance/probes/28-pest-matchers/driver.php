<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * What the generated suites DO on the incumbent's side, as a file.
 *
 * Same argument as cell.php beside it, one level up. cell.php stopped
 * the verdict CLASSIFICATION being written as text after five copies of
 * it produced a false green; these are the loops that drive it, and
 * they were still heredocs — so the code producing the headline parity
 * number lived in exactly the region the generated-code tier exists to
 * eliminate. A string is not code to any gate: not to PHPStan, and not
 * to a clone detector either, which is why the original duplication was
 * invisible to both.
 *
 * Never loaded by Crucible. `require`d by the suites compare.php and
 * regenerate.php write into the oracle's own tests/, so it runs in the
 * incumbent's process and reaches `cell()`, `verdict()` and
 * `inverted()` from cell.php. Checked by `composer analyse:oracles`
 * against the real pest-oracle, never by the default `composer
 * analyse`.
 *
 * What is left as a string in the two entry points is now a call and an
 * `expect(true)->toBeTrue()` — a shape too small to hide a defect, and
 * the assertion is what makes the incumbent count the test at all.
 */

/**
 * The sweep: every matcher over every corpus value, one character per
 * cell, printed as rows for the caller to parse back.
 *
 * @param list<mixed>            $corpus
 * @param list<non-empty-string> $matchers
 */
function crucibleSweep(array $corpus, array $matchers): void
{
    $n = 0;

    foreach ($matchers as $matcher) {
        $row = '';

        foreach ($corpus as $value) {
            $n++;
            $row .= \cell($value, $matcher);
        }

        \printf("ROW %s %s\n", $matcher, $row);
    }

    \printf("COUNTED %d\n", $n);
}

/**
 * The parity re-proof: every recorded verdict asked of the installed
 * incumbent again, and every grid cell re-derived — including under
 * `->not`, which is the relation the record leans on instead of
 * carrying a second grid.
 *
 * Prints nothing when the record still describes the incumbent. Each
 * line it does print names what moved, because a count of divergences
 * without their identity is not evidence.
 *
 * @param list<array{non-empty-string, mixed, string}>         $cases
 * @param array<non-empty-string, string>                      $grid
 * @param list<mixed>                                          $corpus
 * @param list<array{non-empty-string, mixed, string, string}> $claims
 */
function crucibleParity(array $cases, array $grid, array $corpus, array $claims): void
{
    foreach ($cases as [$matcher, $value, $recorded]) {
        $got = \verdict($value, $matcher);

        if ($got !== $recorded) {
            \printf("MOVED     %s(%s): recorded %s, now %s\n", $matcher, \var_export($value, true), $recorded, $got);
        }
    }

    $swept = 0;

    foreach ($grid as $matcher => $verdicts) {
        foreach ($corpus as $i => $value) {
            $swept++;
            $got = \cell($value, $matcher);

            if ($got !== $verdicts[$i]) {
                \printf("MOVED     %s over corpus[%d]: recorded %s, now %s\n", $matcher, $i, $verdicts[$i], $got);
            }

            // The relation the record leans on instead of a second
            // grid: the incumbent's negated verdict is its positive one
            // flipped, and a refusal stays a refusal. Measured true over
            // every cell, and re-proved here every run — if the
            // incumbent ever stops inverting, deriving the negated
            // expectation stops being safe and this says so, rather than
            // the derivation going on being trusted.
            $neg  = \cell($value, $matcher, true);
            $want = \inverted($got);

            if ($neg !== $want) {
                \printf("MOVED     %s over corpus[%d] under ->not: %s positive, %s negated, expected %s\n", $matcher, $i, $got, $neg, $want);
            }
        }
    }

    foreach ($claims as [$matcher, $value, $recorded, $claim]) {
        $swept++;
        $got = \verdict($value, $matcher);

        if ($got !== $recorded) {
            \printf("UNPROVEN  %s(%s) now %s -- the contradiction behind it no longer holds: %s\n", $matcher, \str_replace("\n", ' ', \var_export($value, true)), $got, $claim);
        }
    }

    \printf("COUNTED   %d\n", \count($cases) + $swept);
}
