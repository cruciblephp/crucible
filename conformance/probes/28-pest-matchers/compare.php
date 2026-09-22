<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * Two checks over one recorded table (probe.php).
 *
 *   1. Crucible against the record. Runs everywhere, needs no oracle,
 *      and is the one that catches Crucible drifting.
 *   2. The record against a live Pest, when the oracle is installed.
 *      This is the check the ad-hoc version of this probe could not
 *      make: it catches the INCUMBENT changing under a recording that
 *      still claims to describe it.
 *
 * The second is why the table stores what the incumbent does rather than
 * only what Crucible should do. A parity fixture that records only the
 * agreed answer cannot tell "we broke it" from "they changed it", and
 * those want opposite fixes.
 *
 * Every disagreement must be either absent or named. A row whose `quirk`
 * says which `->quirks()` value reconciles the two is a decision; a row
 * that simply differs is a finding.
 *
 * Both checks sweep BOTH forms of every call. Measuring only the
 * positive form is what let a false pass survive a 3,124-cell sweep:
 * where the incumbent refuses a wrong-typed subject and Crucible
 * answered `false` instead, the positive forms agree (both stop the
 * test) and the negated forms are opposites, because a failure inverts
 * and a refusal does not. `expect([])->not->toBeHostname()` errored
 * there and passed here — green where the incumbent is red, over 373
 * cells this file counted and skipped.
 */

require __DIR__ . '/../../../vendor/autoload.php';
require __DIR__ . '/oracle.php';

use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Configuration\Quirk;
use LucianoPereira\Crucible\Dialect\Pest\Expectation;

$root  = \dirname(__DIR__, 3);
$table = \probeTable();

/** Run one matcher over one value on Crucible's own engine. */
function crucibleVerdict(string $matcher, mixed $value): string
{
    return \crucibleCell($matcher, $value) === 'p' ? 'pass' : 'fail';
}

/**
 * The same, as a grid cell, keeping a refusal apart from a verdict.
 *
 * Catching Throwable and writing 'f' says "the assertion failed" for a
 * case where nothing was asserted — a wrong-typed subject, a matcher
 * that does not exist, a crash. They are not the same measurement and
 * must not share a character.
 *
 * $negated runs the same call through `->not`, which is where that
 * distinction earns its keep. Positive, a refusal and a failure are one
 * outcome; negated they are opposites, because a failure inverts into a
 * pass and a refusal stays a refusal. A refusal recorded as 'f'
 * therefore reads as GREEN under negation, which is exactly how 373
 * cells of this grid hid a false pass.
 */
function crucibleCell(string $matcher, mixed $value, bool $negated = false): string
{
    $expectation = new Expectation($value);

    try {
        $negated ? $expectation->not->{$matcher}() : $expectation->{$matcher}();

        return 'p';
    } catch (AssertionFailedError) {
        return 'f';
    } catch (\Throwable) {
        return 'x';
    }
}

/** A corpus value on one line, for a finding that has to stay greppable. */
function exportOneLine(mixed $value): string
{
    return \str_replace("\n", ' ', \var_export($value, true));
}

/** What `->not` must answer, given the positive verdict: a refusal does not invert. */
function inverted(string $cell): string
{
    return match ($cell) {
        'p'     => 'f',
        'f'     => 'p',
        default => 'x',
    };
}

$findings = 0;
$rows     = 0;
$named    = 0;

// --- 1. Crucible against the record ---------------------------------
// Each declared quirk is measured with that quirk ON, and everything
// else with quirks OFF, so one table proves both configurations.
foreach ($table as $matcher => $cases) {
    foreach ($cases as $case) {
        $rows++;
        $quirk = $case['quirk'] ?? null;

        Expectation::configure($quirk === null ? [] : [Quirk::from($quirk)]);

        $verdict = \crucibleVerdict($matcher, $case['value']);

        if ($verdict === $case['pest']) {
            $named += $quirk === null ? 0 : 1;

            continue;
        }

        $findings++;

        \printf(
            "DRIFT     %s(%s): incumbent says %s, Crucible says %s%s\n",
            $matcher,
            \var_export($case['value'], true),
            $case['pest'],
            $verdict,
            $quirk === null ? '' : ' even with ' . $quirk . ' enabled',
        );
    }
}

Expectation::configure([]);

// A quirk row must ALSO differ with the quirk off, or the quirk is
// dead weight claiming to bridge a gap that closed.
foreach ($table as $matcher => $cases) {
    foreach ($cases as $case) {
        if (!isset($case['quirk'])) {
            continue;
        }

        if (\crucibleVerdict($matcher, $case['value']) !== $case['pest']) {
            continue;
        }

        $findings++;

        \printf(
            "STALE     %s(%s) agrees with the incumbent without %s — the quirk no longer bridges anything\n",
            $matcher,
            \var_export($case['value'], true),
            $case['quirk'],
        );
    }
}

\printf("%-9s %d rows over %d matchers, %d bridged by a named quirk\n", $findings === 0 ? 'OK' : 'CHECKED', $rows, \count($table), $named);

// --- 1b. The full sweep: 44 matchers over the shared corpus ---------
// The 63-row table above is hand-picked and proves the eight matchers
// that were rewritten. This sweeps everything zero-argument, which is
// how the 97 pre-existing divergences were found at all.
$grid     = \probeGrid();
$corpus   = \probeCorpus();
$expected = [];

foreach (\probeDivergences() as [$matcher, $index, $quirk]) {
    $expected[$matcher . '|' . $index] = $quirk;
}

// A row shorter than the corpus reads as '' past its end, which matches
// no verdict, so every trailing value is reported NEW by all 44 matchers
// at once. One BROKEN line naming the stale grid beats 44 findings that
// all mean "sweep.php was not regenerated".
foreach ($grid as $matcher => $verdicts) {
    if (\strlen($verdicts) !== \count($corpus)) {
        \printf(
            "BROKEN    sweep.php row %s carries %d verdicts for %d values — regenerate it from the oracle\n",
            $matcher,
            \strlen($verdicts),
            \count($corpus),
        );

        exit(1);
    }
}

// A divergence naming a matcher outside the grid, or an index outside
// the corpus, is never visited by the sweep — so it survives in $healed
// and is reported as repaired, which tells us to delete a record that
// still stands. A typo must not be able to prune a live finding.
foreach (\array_keys($expected) as $key) {
    [$matcher, $index] = \explode('|', $key);

    if (!isset($grid[$matcher]) || !\array_key_exists((int) $index, $corpus)) {
        \printf("BROKEN    divergences.php names %s, which is not a cell of the grid\n", $key);

        exit(1);
    }

    if ($grid[$matcher][(int) $index] === 'x') {
        \printf("BROKEN    divergences.php names %s, where the incumbent refused the subject rather than answering\n", $key);

        exit(1);
    }
}

// Every divergence exists because the incumbent is wrong, and "wrong"
// is proved by a pair of its own verdicts that cannot both be right.
// The quirk each names must be a real one, or the bridge is a label.
$contradictions = \probeContradictions();
$claims         = [];

foreach ($contradictions as $contradiction) {
    Quirk::from($contradiction['quirk']);

    foreach ($contradiction['proof'] as [$matcher, $value, $verdict]) {
        $claims[] = [$matcher, $value, $verdict, $contradiction['claim']];
    }
}

$swept    = 0;
$refused  = 0;
$unknown  = [];
$answered = [];
$oneWay   = [];
$healed   = $expected;

foreach ($grid as $matcher => $verdicts) {
    foreach ($corpus as $index => $value) {
        $swept++;

        $crucible = \crucibleCell($matcher, $value);
        $negated  = \crucibleCell($matcher, $value, negated: true);
        $key      = $matcher . '|' . $index;

        // Both forms of every cell, not just the positive one. Measuring
        // only the positive form is why the false-green bug survived a
        // 3,124-cell sweep: the two forms agree on every cell where
        // Crucible is right, and disagree on exactly the ones where it
        // is not. There is no second grid to keep in sync because the
        // incumbent's negated verdict is its positive one flipped — all
        // 44 rows, measured — and the generated suite below re-proves
        // that against the live incumbent rather than assuming it.
        if ($negated !== \inverted($crucible)) {
            $oneWay[] = \sprintf(
                '%s(%s): %s positive, %s negated, expected %s',
                $matcher,
                \exportOneLine($value),
                $crucible,
                $negated,
                \inverted($crucible),
            );
        }

        // Where the incumbent refused the subject's type there is no
        // verdict to disagree with — but Crucible has to refuse too, and
        // that is the opposite of a formality. Answering instead is
        // invisible in the positive form (both stop the test) and
        // becomes a PASS under `->not`, so a migrated suite goes green
        // exactly where it was red. These 373 cells were counted and
        // skipped by the sweep that was supposed to catch it.
        if ($verdicts[$index] === 'x') {
            $refused++;

            if ($crucible !== 'x') {
                $answered[] = \sprintf(
                    '%s(%s): answered %s where the incumbent refuses the subject type',
                    $matcher,
                    \exportOneLine($value),
                    $crucible,
                );
            }

            continue;
        }

        if ($crucible === $verdicts[$index]) {
            // Agreeing where a divergence was recorded is good news, but
            // it still has to be pruned: a record that outlived the thing
            // it described is the failure the drift checks exist for.
            continue;
        }

        unset($healed[$key]);

        if (!isset($expected[$key])) {
            $unknown[] = \sprintf('%s(%s)', $matcher, \exportOneLine($value));
        }
    }
}

foreach ($unknown as $case) {
    echo 'NEW       ', $case, " diverges from the incumbent and is not in divergences.php\n";
    $findings++;
}

foreach ($answered as $case) {
    echo 'ANSWERED  ', $case, " — under ->not that refusal becomes a PASS\n";
    $findings++;
}

foreach ($oneWay as $case) {
    echo 'ONEWAY    ', $case, " — ->not is not the inverse of the positive form\n";
    $findings++;
}

foreach (\array_keys($healed) as $key) {
    echo 'HEALED    ', $key, " no longer diverges — prune it from divergences.php\n";
    $findings++;
}

// Every divergence exists because the incumbent is wrong, so every one
// must be reproducible on demand: with its quirk on, Crucible has to
// answer exactly as the incumbent does. A divergence nobody can opt
// back into is a difference nobody decided, which is the state this
// record exists to leave behind.
$unbridged = 0;

foreach ($expected as $key => $quirkName) {
    [$matcher, $index] = \explode('|', $key);
    $index             = (int) $index;

    Expectation::configure([Quirk::from($quirkName)]);

    // Through crucibleCell() rather than a local catch-Throwable, so a
    // quirk that "restores" the incumbent's answer by refusing the
    // subject outright cannot read as having restored a verdict.
    $bridged = \crucibleCell($matcher, $corpus[$index]);

    if ($bridged === $grid[$matcher][$index]) {
        continue;
    }

    $findings++;
    $unbridged++;

    \printf(
        "UNBRIDGED %s over corpus[%d]: %s does not restore the incumbent's answer\n",
        $matcher,
        $index,
        $quirkName,
    );
}

Expectation::configure([]);

\printf(
    "%-9s %d cells over %d matchers, both forms, %d known divergences carried, %d bridged, %d refusals matched\n",
    $unknown === [] && $healed === [] && $answered === [] && $oneWay === [] && $unbridged === 0 ? 'OK' : 'CHECKED',
    $swept,
    \count($grid),
    \count($expected),
    \count($expected) - $unbridged,
    $refused,
);

// --- 1c. What the sweep covers, out of what exists -------------------
// The line above is a true number answering the wrong question until it
// says 44 of what. Derived by reflection every run, never written down:
// a recorded denominator is exactly the thing that goes stale — the
// project's own plan carried "18 excluded, 39 with arguments" while the
// tree says 22 and 35.
$declared = \probeSurface();

$excluded = \probeExcluded();

// A stale exclusion prunes a matcher from the denominator while naming
// something that no longer exists, so it is fatal rather than counted.
foreach (\array_keys($excluded) as $matcher) {
    if (!isset($declared[$matcher])) {
        \printf("BROKEN    excluded.php names %s, which Expectation does not declare\n", $matcher);

        exit(1);
    }
}

$reasons  = [];
$unnamed  = [];
$withArgs = 0;

foreach ($declared as $matcher => $required) {
    if (isset($grid[$matcher])) {
        continue;
    }

    // Taking arguments is a GAP — the harness has no axis for it — not a
    // decision, so it is counted separately and never wants a reason here.
    if ($required > 0) {
        $withArgs++;

        continue;
    }

    if (!isset($excluded[$matcher])) {
        $unnamed[] = $matcher;

        continue;
    }

    $reasons[$excluded[$matcher]] = ($reasons[$excluded[$matcher]] ?? 0) + 1;
}

// The gate: a new zero-argument matcher the sweep could have taken must
// be swept or refused on the record. Without this the denominator drifts
// silently and the verdict above keeps reading OK over a smaller share.
foreach ($unnamed as $matcher) {
    \printf("UNSWEPT   %s takes no arguments and is neither swept nor named in excluded.php\n", $matcher);
    $findings++;
}

\ksort($reasons);

$breakdown = [];

foreach ($reasons as $reason => $count) {
    $breakdown[] = $reason . ' ' . $count;
}

// "off this axis", not "uncompared": the 27 arch matchers are swept by
// compare-arch.php. The other three reasons compare nothing.
\printf(
    "%-9s %d of %d declared matchers swept here; %d off this axis (%s — arch is swept by analyse:arch), %d take arguments and have no axis\n",
    $unnamed === [] ? 'OK' : 'CHECKED',
    \count($grid),
    \count($declared),
    \array_sum($reasons),
    \implode(', ', $breakdown),
    $withArgs,
);

// --- 2. The record against a live incumbent -------------------------
$oracle = \oracleBinary($root);

if ($oracle['binary'] === null) {
    echo 'SKIPPED   the record was not re-proved: ', $oracle['install'], "\n";

    exit($findings === 0 ? 0 : 1);
}

$cases = [];

foreach ($table as $matcher => $rowsFor) {
    foreach ($rowsFor as $case) {
        $cases[] = [$matcher, $case['value'], $case['pest']];
    }
}

// The grid travels inside the generated file; the corpus is REQUIRED
// from the one canonical values.php by absolute path. Still a single
// corpus file -- nothing is copied next to the oracle, so there is no
// second tree to keep in sync -- but not a serialize round trip either,
// and that is what lets the corpus hold a value var_export() cannot
// carry. Measured: var_export(new SplFileInfo('abc')) emits
// \SplFileInfo::__set_state(array(...)) and SplFileInfo has no
// __set_state, so it is a FATAL on the far side; ArrayObject and
// Closure the same. Only stdClass round-trips. Every object in the
// corpus was unreachable until the transport changed.
//
// cell(), inverted() and verdict() come from oraclePrelude(), so the
// four loops below classify a verdict ONE way rather than four.
$body = <<<'PHP'
    test('parity', function () use ($cases, $grid, $corpus, $claims): void {
        crucibleParity($cases, $grid, $corpus, $claims);
        expect(true)->toBeTrue();
    });

    PHP;

$output = \oracleRun(
    $oracle['binary'],
    'CrucibleParityTest.php',
    '$cases  = ' . \var_export($cases, true) . ";\n"
    . '$grid   = ' . \var_export($grid, true) . ";\n"
    . '$corpus = require ' . \var_export(__DIR__ . '/values.php', true) . ";\n"
    . '$claims = ' . \var_export($claims, true) . ";\n\n"
    . \oraclePrelude()
    . "\n" . $body,
);

$moved = \array_values(\array_filter($output, static fn(string $line): bool => \str_starts_with($line, 'MOVED') || \str_starts_with($line, 'UNPROVEN')));

foreach ($moved as $line) {
    echo $line, "\n";
}

// The count the incumbent reports back, checked against the count sent.
$counted = \oracleCounted($output);

if ($counted !== $rows + $swept + \count($claims)) {
    \printf("BROKEN    the incumbent checked %d of %d cases — the probe did not run what it claims\n", $counted, $rows + $swept + \count($claims));

    exit(1);
}

echo $moved === [] ? \sprintf("OK        the record still describes the installed incumbent (%d rows re-proved)\n", $counted) : \sprintf("CHECKED   %d recorded verdict(s) no longer match the incumbent\n", \count($moved));

exit($findings === 0 && $moved === [] ? 0 : 1);
