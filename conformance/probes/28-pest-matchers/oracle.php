<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

/*
 * The oracle round trip, shared by compare.php and regenerate.php.
 *
 * Both entry points do the same four things — write a suite into the
 * incumbent's own tests/, run it from the incumbent's own directory,
 * delete it, and reconcile the count it reports back — and both used to
 * spell all four out. The classification of a verdict was spelled out
 * five times over the two files, and that duplication is not incidental
 * to this probe's history: the old code caught `Throwable` and wrote
 * `'f'`, so moving to three states meant editing every copy, and the
 * negated sweep then added two more. The count went 3 -> 5 WHILE the
 * bug the duplication had caused was being fixed.
 *
 * Nothing flagged it, because nothing scanned this directory: phpstan's
 * paths were `src` + `tests/unit`, and the duplication check defaults to
 * `src`. It was the largest body of PHP in the repository with no type
 * or clone gate over it, and it is the code every parity claim rests on.
 *
 * ONE thing is deliberately not shared, and it is worth saying so the
 * next reader does not try. compare.php's own `crucibleCell()` looks
 * like `cell()` below and cannot be merged with it: they run in
 * different processes against different engines, and they catch
 * different exception types — Crucible's AssertionFailedError against
 * the incumbent's PHPUnit\Framework\ExpectationFailedException. Sharing
 * the shape would mean one of them classifying by a name its own engine
 * never raises, which is how a refusal would come to read as a failure
 * again.
 */

/**
 * Absolute path to the incumbent's binary, or null when it is absent.
 *
 * @return array{binary: ?string, install: non-empty-string}
 */
function oracleBinary(string $root): array
{
    /** @var array<string, array{probe: string, install: non-empty-string}> $oracles */
    $oracles = require $root . '/conformance/oracles-registry.php';
    $binary  = $root . '/' . $oracles['pest-oracle']['probe'];

    return [
        'binary'  => \file_exists($binary) ? $binary : null,
        'install' => $oracles['pest-oracle']['install'],
    ];
}

/**
 * The verdict helpers every generated suite calls, as a `require` line.
 *
 * They live in cell.php — a real file rather than text this function
 * emits — so a type checker can read them. That is not a nicety: this
 * classification once existed in five copies, every one inside a string
 * literal being concatenated into a generated suite, and code inside a
 * string is invisible to every static gate. Measured, phpcpd reports no
 * clone in this directory at its default settings either before or after
 * those copies were removed, because a tokeniser sees string tokens. The
 * fix for string-built code is to write less of it, not to point a gate
 * at it.
 *
 * cell.php names PHPUnit's exception, which Crucible does not depend on,
 * so `composer analyse:oracles` checks it against the real pest-oracle.
 */
function oraclePrelude(): string
{
    return 'require ' . \var_export(__DIR__ . '/cell.php', true) . ";\n"
        . 'require ' . \var_export(__DIR__ . '/driver.php', true) . ";\n\n";
}

/**
 * Write the suite into the incumbent's tests/, run it, delete it.
 *
 * Run from the oracle's OWN directory: the incumbent resolves its
 * phpunit.xml relative to the working directory, and started from
 * Crucible's it reads Crucible's instead and collects nothing.
 *
 * Writing into `pest-oracle/tests/` is the established exception to the
 * read-only rule for oracle checkouts — the file is deleted on the way
 * out, and `pest-oracle` is gitignored and pint-excluded.
 *
 * @return list<string>
 */
function oracleRun(string $binary, string $suiteName, string $source): array
{
    $suite = \dirname($binary, 3) . '/tests/' . $suiteName;

    \file_put_contents($suite, "<?php\n\n" . $source);

    $output = [];
    \exec(
        'cd ' . \escapeshellarg(\dirname($binary, 3))
        . ' && ' . \escapeshellarg('vendor/bin/pest')
        . ' ' . \escapeshellarg('tests/' . $suiteName) . ' 2>&1',
        $output,
    );

    \unlink($suite);

    return $output;
}

/**
 * The count the suite reported back, or 0 when it reported none.
 *
 * Checked against the count sent, by every caller. Without it a probe
 * that silently ran NOTHING — a wrong path, a suite the runner declined
 * to collect — prints the same reassuring OK as one that checked every
 * row, and a green light nobody earned is worse than a red one.
 *
 * @param list<string> $output
 */
function oracleCounted(array $output, string $label = 'COUNTED'): int
{
    foreach ($output as $line) {
        if (\str_starts_with($line, $label)) {
            return (int) \trim(\substr($line, \strlen($label)));
        }
    }

    return 0;
}

/*
 * The data files, loaded through a check rather than a cast.
 *
 * `require` is `mixed` to PHPStan, and this directory now sits under the
 * type gate, so each load either proves its shape or fails loudly. The
 * check earns its place rather than silencing the analyser: these files
 * are edited both by hand and by generator, and a malformed one used to
 * surface as a puzzling verdict a long way from the edit instead of as a
 * complaint about the file that is wrong.
 */

/**
 * The shared corpus. Deliberately `mixed` per item — that is the point.
 *
 * @return list<mixed>
 */
function probeCorpus(): array
{
    $corpus = require __DIR__ . '/values.php';

    if (!\is_array($corpus) || !\array_is_list($corpus) || $corpus === []) {
        throw new \RuntimeException('values.php must return a non-empty list');
    }

    return $corpus;
}

/**
 * @return list<non-empty-string>
 */
function probeMatchers(): array
{
    $matchers = require __DIR__ . '/matchers.php';
    $out      = [];

    if (!\is_array($matchers)) {
        throw new \RuntimeException('matchers.php must return a list of matcher names');
    }

    foreach ($matchers as $matcher) {
        if (!\is_string($matcher) || $matcher === '') {
            throw new \RuntimeException('matchers.php: every entry is a matcher name');
        }

        $out[] = $matcher;
    }

    return $out;
}

/**
 * The zero-argument matchers deliberately not swept, and why.
 *
 * @return array<non-empty-string, non-empty-string> matcher => reason
 */
function probeExcluded(): array
{
    $excluded = require __DIR__ . '/excluded.php';
    $out      = [];

    if (!\is_array($excluded)) {
        throw new \RuntimeException('excluded.php must return a map of matcher => reason');
    }

    foreach ($excluded as $matcher => $reason) {
        if (!\is_string($matcher) || $matcher === '' || !\is_string($reason) || $reason === '') {
            throw new \RuntimeException('excluded.php: every entry is matcher => reason');
        }

        $out[$matcher] = $reason;
    }

    return $out;
}

/**
 * @return array<non-empty-string, non-empty-string>
 */
function probeGrid(): array
{
    $grid = require __DIR__ . '/sweep.php';
    $out  = [];

    if (!\is_array($grid)) {
        throw new \RuntimeException('sweep.php must return a grid keyed by matcher');
    }

    foreach ($grid as $matcher => $verdicts) {
        if (!\is_string($matcher) || $matcher === '' || !\is_string($verdicts) || $verdicts === '') {
            throw new \RuntimeException('sweep.php: every row is matcher => verdict characters');
        }

        $out[$matcher] = $verdicts;
    }

    return $out;
}

/**
 * @return list<array{0: non-empty-string, 1: int, 2: non-empty-string}>
 */
function probeDivergences(): array
{
    $rows = require __DIR__ . '/divergences.php';
    $out  = [];

    if (!\is_array($rows)) {
        throw new \RuntimeException('divergences.php must return a list of rows');
    }

    foreach ($rows as $row) {
        if (
            !\is_array($row)
            || !\is_string($row[0] ?? null) || $row[0] === ''
            || !\is_int($row[1] ?? null)
            || !\is_string($row[2] ?? null) || $row[2] === ''
        ) {
            throw new \RuntimeException('divergences.php: every row is [matcher, corpus index, quirk]');
        }

        $out[] = [$row[0], $row[1], $row[2]];
    }

    return $out;
}

/**
 * @return list<array{group: non-empty-string, quirk: non-empty-string, claim: non-empty-string, truth: non-empty-string, proof: list<array{0: non-empty-string, 1: mixed, 2: 'pass'|'fail'}>}>
 */
function probeContradictions(): array
{
    $entries = require __DIR__ . '/contradictions.php';
    $out     = [];

    if (!\is_array($entries)) {
        throw new \RuntimeException('contradictions.php must return a list of entries');
    }

    foreach ($entries as $entry) {
        if (!\is_array($entry)) {
            throw new \RuntimeException('contradictions.php: every entry is a map');
        }

        $group = $entry['group'] ?? null;
        $quirk = $entry['quirk'] ?? null;
        $claim = $entry['claim'] ?? null;
        $truth = $entry['truth'] ?? null;
        $proof = $entry['proof'] ?? null;

        if (
            !\is_string($group) || $group === ''
            || !\is_string($quirk) || $quirk === ''
            || !\is_string($claim) || $claim === ''
            || !\is_string($truth) || $truth === ''
            || !\is_array($proof)
        ) {
            throw new \RuntimeException('contradictions.php: an entry needs group, quirk, claim, truth and proof');
        }

        $rows = [];

        foreach ($proof as $row) {
            if (
                !\is_array($row)
                || !\is_string($row[0] ?? null) || $row[0] === ''
                || !\in_array($row[2] ?? null, ['pass', 'fail'], true)
            ) {
                throw new \RuntimeException('contradictions.php: every proof row is [matcher, value, pass|fail]');
            }

            $rows[] = [$row[0], $row[1] ?? null, $row[2]];
        }

        $out[] = ['group' => $group, 'quirk' => $quirk, 'claim' => $claim, 'truth' => $truth, 'proof' => $rows];
    }

    return $out;
}

/**
 * The hand-picked table, which records only pass/fail because every one
 * of its subjects is one the incumbent answers about.
 *
 * @return array<non-empty-string, list<array{value: string, pest: 'pass'|'fail', quirk?: non-empty-string}>>
 */
function probeTable(): array
{
    $table = require __DIR__ . '/probe.php';
    $out   = [];

    if (!\is_array($table)) {
        throw new \RuntimeException('probe.php must return a table keyed by matcher');
    }

    foreach ($table as $matcher => $cases) {
        if (!\is_string($matcher) || $matcher === '' || !\is_array($cases)) {
            throw new \RuntimeException('probe.php: every row is matcher => list of cases');
        }

        $rows = [];

        foreach ($cases as $case) {
            if (!\is_array($case) || !\is_string($case['value'] ?? null) || !\in_array($case['pest'] ?? null, ['pass', 'fail'], true)) {
                throw new \RuntimeException('probe.php: every case needs a string value and a pass/fail verdict');
            }

            $row   = ['value' => $case['value'], 'pest' => $case['pest']];
            $quirk = $case['quirk'] ?? null;

            if (\is_string($quirk) && $quirk !== '') {
                $row['quirk'] = $quirk;
            }

            $rows[] = $row;
        }

        $out[$matcher] = $rows;
    }

    return $out;
}

/**
 * The public matcher surface, by reflection — the one definition of
 * "what exists to be covered", so the sweep's denominator and the
 * ownership gate cannot count different things.
 *
 * @return array<non-empty-string, int> matcher => required arguments
 */
function probeSurface(): array
{
    $declared = [];

    foreach ((new \ReflectionClass(\LucianoPereira\Crucible\Dialect\Pest\Expectation::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();

        if (\preg_match('/^(?:to|have)[A-Z]/', $name) === 1) {
            $declared[$name] = $method->getNumberOfRequiredParameters();
        }
    }

    return $declared;
}
