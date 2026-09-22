<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Compat\Migration;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use SimpleXMLElement;

use function file_get_contents;
use function is_file;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function property_exists;
use function rtrim;
use function simplexml_load_string;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;
use function stripos;
use function strlen;
use function substr;

/**
 * Runs the project's real, installed PHPUnit binary as a subprocess —
 * the oracle side of `compat-check` (the same black-box comparison
 * conformance/run.php does for Crucible's own maintainer-only
 * fixtures, D-018, adapted here for a real end-user suite). Keyed by
 * "<relative file path>::method" (plus "#dataset") — matching
 * Crucible's own NDJSON `id` shape exactly (confirmed empirically: it
 * identifies a test by its source file, not its FQCN), not the
 * FQCN-based keying an earlier version of this class assumed, which
 * silently matched nothing at all across an entire real suite.
 *
 * On a pest project the oracle is `vendor/bin/pest`, because ✓ measured
 * the real phpunit binary cannot run a pest suite at all — pest refuses
 * from inside its own TestSuite and collects nothing (D-114). Pest
 * reports through the same JUnit logger, in its own shape: the `file`
 * attribute already carries `<relative file>::<test name>`, which is
 * Crucible's id exactly, so those rows need no translation beyond
 * their dataset suffix.
 */
final readonly class OracleRun
{
    /**
     * @param non-empty-string       $binary           path to the incumbent's binary
     * @param list<non-empty-string> $extraArgs        forwarded CLI args (--filter, --testsuite, ...)
     * @param non-empty-string       $junitFile        where to write --log-junit
     * @param ?non-empty-string      $eventsFile       where to write --log-events-text, for the rows JUnit cannot locate
     *
     * @return array{outcomes: array<string, string>, unmapped: list<string>} keyed "<relative file>::method#dataset" => pass|fail|error|risky|skip
     */
    public static function outcomes(string $binary, array $extraArgs, WorkingDirectory $workingDirectory, string $junitFile, ?string $eventsFile = null): array
    {
        // Both logs from ONE run. The events log is asked for only
        // where JUnit is known to lose the file — the pest oracle — so
        // an older phpunit is never handed an option it would refuse.
        $command = [$binary, '--log-junit', $junitFile];

        if ($eventsFile !== null) {
            $command[] = '--log-events-text';
            $command[] = $eventsFile;
        }

        self::run([...$command, ...$extraArgs], $workingDirectory->path);

        return self::parse($junitFile, $workingDirectory);
    }

    /**
     * @param list<string> $command
     */
    private static function run(array $command, string $cwd): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);

        if (!is_resource($process)) {
            return;
        }

        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        proc_close($process);
    }

    /**
     * The oracle binary for this project: pest when it is installed,
     * the real phpunit otherwise, null when neither is.
     *
     * ✓ Measured: `vendor/bin/phpunit` cannot run a pest suite at all —
     * pest refuses from inside its own TestSuite and collects nothing,
     * so on a pest project the phpunit binary is not a narrower oracle,
     * it is an empty one (D-114). Pest is preferred wherever it is
     * present, because a project that has it is a project whose tests
     * it owns.
     *
     *
     * @return ?non-empty-string
     */
    public static function binaryIn(WorkingDirectory $workingDirectory): ?string
    {
        $root = rtrim($workingDirectory->path, '/');

        foreach ([$root . '/vendor/bin/pest', $root . '/vendor/bin/phpunit'] as $binary) {
            if (is_file($binary)) {
                return $binary;
            }
        }

        return null;
    }

    /**
     * The pure parsing half, public so it can be unit-tested against a
     * fixture JUnit file without a real PHPUnit subprocess.
     *
     * Both answers come from ONE parse. They used to be two public
     * methods over the same file, so a single compat-check run read and
     * built the XML tree twice to learn two things about the same rows.
     *
     * The second answer is the rows no Crucible id can be built for.
     * They are returned rather than dropped, and rather than guessed
     * at: silently leaving them out would shrink the comparison without
     * saying so, which is a vacuous pass one size down — the tool would
     * look like it compared a suite when it compared part of one.
     *
     * @param non-empty-string $junitFile
     *
     * @return array{outcomes: array<string, string>, unmapped: list<string>}
     */
    public static function parse(string $junitFile, WorkingDirectory $workingDirectory): array
    {
        $outcomes = [];
        $unmapped = [];

        foreach (self::testcases($junitFile, $workingDirectory) as [$key, $outcome, $name]) {
            if ($key === null) {
                $unmapped[] = $name;

                continue;
            }

            $outcomes[$key] = $outcome;
        }

        return ['outcomes' => $outcomes, 'unmapped' => $unmapped];
    }

    /**
     * @param non-empty-string $junitFile
     *
     * @return list<array{0: ?non-empty-string, 1: string, 2: string}> key (null when unmappable), outcome, reported name
     */
    private static function testcases(string $junitFile, WorkingDirectory $workingDirectory): array
    {
        $xml = simplexml_load_string((string) file_get_contents($junitFile));

        if ($xml === false) {
            return [];
        }

        $rows = [];

        /** @var array<string, int> $ordinals */
        $ordinals = [];

        foreach ($xml->xpath('//testcase') ?? [] as $testcase) {
            $outcome = 'pass';

            if (property_exists($testcase, 'failure') && $testcase->failure !== null) {
                $outcome = 'fail';
            } elseif (property_exists($testcase, 'error') && $testcase->error !== null) {
                $type    = (string) $testcase->error['type'];
                $outcome = stripos($type, 'risky') !== false ? 'risky' : 'error';
            } elseif (property_exists($testcase, 'skipped') && $testcase->skipped !== null) {
                $outcome = 'skip';
            }

            $rows[] = [self::key($testcase, $workingDirectory, $ordinals), $outcome, (string) $testcase['name']];
        }

        return $rows;
    }

    /**
     * "<relative file>::method" (plus "#dataset"), matching Crucible's
     * own NDJSON `id` shape directly, so no translation is needed to
     * compare the two sides.
     *
     * @param array<string, int> &$ordinals         dataset counters, per test, in execution order
     *
     * @return ?non-empty-string
     */
    private static function key(SimpleXMLElement $testcase, WorkingDirectory $workingDirectory, array &$ordinals): ?string
    {
        $name = (string) $testcase['name'];
        $file = (string) $testcase['file'];

        // Pest's shape: the `file` attribute already holds
        // "<relative file>::<test name>" — Crucible's id, dataset
        // aside. ✓ Measured: for a classic PHPUnit class inside a pest
        // run the same attribute holds pest's DESCRIPTION of the class
        // ("Classic (Tests\Classic)"), which names no file anywhere,
        // and pest's teamcity locationHint says the same. That is why
        // those rows come back unmappable instead of guessed at.
        if ($name !== '' && str_ends_with($file, '::' . $name)) {
            return self::pestKey(substr($file, 0, -strlen('::' . $name)), $name, $workingDirectory, $ordinals);
        }

        preg_match('/^(?<m>.+?)(?: with data set (?:"(?<n>.+)"|#(?<i>\d+)))?$/s', $name, $match);

        $method  = $match['m'] ?? $name;
        $dataset = ($match['n'] ?? '') !== '' ? $match['n'] : (($match['i'] ?? '') !== '' ? $match['i'] : '');

        return sprintf('%s::%s%s', self::relative($file, $workingDirectory), $method, $dataset !== '' ? '#' . $dataset : '');
    }

    /**
     * The pest-shaped key, or null when the path names no file.
     *
     * @param array<string, int> &$ordinals
     *
     * @return ?non-empty-string
     */
    private static function pestKey(string $path, string $name, WorkingDirectory $workingDirectory, array &$ordinals): ?string
    {
        $relative = self::relative($path, $workingDirectory);
        $absolute = str_starts_with($relative, '/') ? $relative : rtrim($workingDirectory->path, '/') . '/' . $relative;

        if ($relative === '' || !is_file($absolute)) {
            return null;
        }

        if (preg_match('/^(?<t>.*) with data set "(?<label>.*)"$/s', $name, $match) !== 1) {
            return $relative . '::' . $name;
        }

        $test = $relative . '::' . $match['t'];

        // A named set carries its own name, and Crucible's id uses that
        // name. A positional one is labelled with its VALUES — "(1)" —
        // where the id holds the ORDINAL, which the label never
        // contains. JUnit lists testcases in execution order and the
        // ordinal counts in that same order, so they are counted as
        // they arrive rather than parsed out of a label that has no
        // ordinal in it.
        if (preg_match('/^dataset "(?<n>.*)"$/s', $match['label'], $named) === 1) {
            return $test . '#' . $named['n'];
        }

        $ordinal         = $ordinals[$test] ?? 0;
        $ordinals[$test] = $ordinal + 1;

        return $test . '#' . $ordinal;
    }

    private static function relative(string $absoluteFile, WorkingDirectory $workingDirectory): string
    {
        $prefix = rtrim($workingDirectory->path, '/') . '/';

        return str_starts_with($absoluteFile, $prefix) ? substr($absoluteFile, strlen($prefix)) : $absoluteFile;
    }
}
