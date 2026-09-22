<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Compat\Migration\CrucibleRun;
use LucianoPereira\Crucible\Compat\Migration\FixManifest;
use LucianoPereira\Crucible\Compat\Migration\KnownCouplingPatterns;
use LucianoPereira\Crucible\Compat\Migration\OracleRun;
use LucianoPereira\Crucible\Compat\Migration\OutcomeDiff;
use LucianoPereira\Crucible\Compat\Migration\PhpUnitEventLog;
use LucianoPereira\Crucible\Compat\Migration\SourceRewriter;
use LucianoPereira\Crucible\Compat\PhpUnitCompatibility;
use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

use function array_keys;
use function array_slice;
use function basename;
use function count;
use function explode;
use function fgets;
use function file_get_contents;
use function file_put_contents;
use function getmypid;
use function in_array;
use function is_file;
use function printf;
use function realpath;
use function rtrim;
use function sprintf;
use function strtolower;
use function sys_get_temp_dir;
use function trim;
use function unlink;

use const PHP_EOL;
use const STDIN;

/**
 * `crucible compat-check`: runs the project's real, installed PHPUnit
 * alongside Crucible, finds tests the oracle passes but Crucible
 * doesn't, and diagnoses why — the productized version of a manual
 * compat audit. Auto-fix only ever touches the small, explicit table
 * in KnownCouplingPatterns; anything else is diagnosed, never guessed
 * at, and the interactive path always asks before writing.
 */
final class CompatCheckCommand
{
    /**
     * @param list<string>     $argv
     */
    public function execute(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        $manifest = new FixManifest(
            $workingDirectory->path . '/' . ($options->cacheDirectory ?? '.crucible.cache') . '/compat-fixes.json',
        );

        if ($options->revert) {
            return $this->revert($manifest);
        }

        // Every exit below is either 1 — the engines disagree, which
        // is a finding about the code — or 2, the run could not be
        // performed, which is a finding about the setup. HELP.md's
        // table already draws that line ("no tests found" is a 2), and
        // a CI job acts on the two differently: fix the tests, or fix
        // the configuration.
        if (!PhpUnitCompatibility::phpUnitIsInstalled()) {
            print 'compat-check needs the real phpunit/phpunit installed alongside Crucible '
                . '(it is the oracle being compared against) — composer require --dev phpunit/phpunit.' . PHP_EOL;

            return 2;
        }

        // Pest when the project has it, phpunit otherwise: measured,
        // the phpunit binary cannot run a pest suite at all, so on a
        // pest project it is not a narrower oracle but an empty one
        // (D-114).
        $oracleBinary = OracleRun::binaryIn($workingDirectory);

        if ($oracleBinary === null) {
            printf('Cannot find an oracle binary — looked for vendor/bin/pest and vendor/bin/phpunit in %s.' . PHP_EOL, $workingDirectory->path);

            return 2;
        }

        $crucibleBinary = realpath($argv[0] ?? '');

        if ($crucibleBinary === false) {
            print 'Cannot resolve the crucible binary path for the comparison run.' . PHP_EOL;

            return 2;
        }

        $forwarded = $this->forwardedArgs($argv);
        $tmp       = sys_get_temp_dir() . '/crucible-compat-check-' . getmypid();

        // The events log is asked for only from pest, the one oracle
        // whose JUnit loses the file (D-118). An older phpunit is never
        // handed an option it would refuse.
        $eventsFile = basename($oracleBinary) === 'pest' ? $tmp . '-events.txt' : null;

        printf('Running the real %s oracle...' . PHP_EOL, basename($oracleBinary));
        ['outcomes' => $oracle, 'unmapped' => $unmapped] = OracleRun::outcomes(
            $oracleBinary,
            $forwarded,
            $workingDirectory,
            $tmp . '-junit.xml',
            $eventsFile,
        );

        if (is_file($tmp . '-junit.xml')) {
            unlink($tmp . '-junit.xml');
        }

        print 'Running Crucible...' . PHP_EOL;
        $crucible = CrucibleRun::outcomes($crucibleBinary, $forwarded, $workingDirectory, $tmp . '-events.ndjson');

        print PHP_EOL;

        // What pest described instead of locating is asked of the
        // engine that owns it, before any verdict — recovering those
        // rows widens the comparison, and what stays unrecovered
        // narrows what the verdict is ABOUT.
        $lost = 0;

        if ($unmapped !== [] && $eventsFile !== null && is_file($eventsFile)) {
            $placed = PhpUnitEventLog::keyedByFile(
                PhpUnitEventLog::parse($eventsFile),
                self::classesInCandidateFiles($oracle, $crucible, $workingDirectory),
            );

            $oracle = [...$oracle, ...$placed['outcomes']];

            $lost = $this->reportUnmapped($unmapped, $placed['outcomes'], $placed['unlocated']);
        } elseif ($unmapped !== []) {
            $lost = $this->reportUnmapped($unmapped, [], []);
        }

        if ($eventsFile !== null && is_file($eventsFile)) {
            unlink($eventsFile);
        }

        $drifted = OutcomeDiff::driftedToFailure($oracle, $crucible);
        $missing = OutcomeDiff::missingFromCrucible($oracle, $crucible);

        // And before that: an oracle that collected nothing leaves both
        // lists empty, which is exactly what agreement is spelled with.
        $vacuous = self::nothingToCompare($oracle, $crucible);

        if ($vacuous !== null) {
            print $vacuous;

            return 2;
        }

        // Before any verdict: a test Crucible never ran is not a test
        // Crucible agrees about. Comparing outcomes alone cannot see
        // that, so nothing below is meaningful until it is ruled out.
        if ($missing !== []) {
            return $this->reportMissing($oracle, $crucible, $missing);
        }

        if ($drifted === []) {
            // A partial comparison does not get the clean sentence
            // first and a caveat after it. What a reader takes from a
            // skim is the verdict, so the qualifier is IN the verdict:
            // the unearned green this command exists to refuse is one
            // that reads green.
            if ($lost > 0) {
                printf(
                    'No drift among the %d test(s) both engines could be asked about — but %d could not be, '
                        . 'so the suite is not proven.' . PHP_EOL,
                    count($oracle),
                    $lost,
                );

                return 2;
            }

            // The count is part of the verdict, not decoration: this
            // command's failure mode is agreeing about nothing, and a
            // clean run that does not say how much it compared cannot
            // be told from one that compared almost none of it.
            printf(
                'No drift: all %d test(s) the real %s oracle passes, Crucible also passes.' . PHP_EOL,
                count($oracle),
                basename($oracleBinary),
            );

            return 0;
        }

        return $this->handleDrift($drifted, $manifest, $workingDirectory, $options->autoFix, basename($oracleBinary));
    }

    /**
     * The classes declared by the files the oracle could not key.
     *
     * The candidate set is implied by the two runs already made: every
     * file Crucible reported an id in, minus every file the oracle
     * managed to key. Only those files are scanned, and the scan is
     * ClassLocator's token pass — a file that declares a class says so
     * in its own grammar, so this needs no loading and no inference.
     *
     * A pest-shaped file is deliberately NOT excluded here. It was, for
     * as long as the recovery meant handing the file to phpunit, which
     * dies inside pest on such a file — and that exclusion is what
     * capped the comparison at 3.3% of a real suite (D-117), because a
     * classic class living in a pest-shaped file is exactly the case
     * that needed recovering. Reading the run's own event log asks no
     * engine to load anything, so the guard has nothing left to guard.
     *
     * @param array<string, string> $oracle
     * @param array<string, string> $crucible
     *
     * @return array<string, non-empty-string> class => project-relative file
     */
    public static function classesInCandidateFiles(array $oracle, array $crucible, WorkingDirectory $workingDirectory): array
    {
        $answered = [];

        foreach (array_keys($oracle) as $key) {
            $answered[explode('::', $key, 2)[0]] = true;
        }

        $locator = new ClassLocator();
        $classes = [];
        $seen    = [];

        foreach (array_keys($crucible) as $key) {
            $file = explode('::', $key, 2)[0];

            if ($file === '' || isset($answered[$file]) || isset($seen[$file])) {
                continue;
            }

            $seen[$file] = true;
            $absolute    = rtrim($workingDirectory->path, '/') . '/' . $file;

            if (!is_file($absolute)) {
                continue;
            }

            foreach ($locator->classesIn($absolute) as $class) {
                $classes[$class] = $file;
            }
        }

        return $classes;
    }

    /**
     * What the first oracle pass could not key, and what became of it.
     *
     * Anything the second pass did not recover leaves the comparison
     * and is named here rather than dropped: quietly shrinking the
     * compared suite is [nothingToCompare]'s defect one size down, and
     * the harder one to notice, because the number that shrinks is
     * never printed. When there was nothing to retry the cause is
     * usually one thing, and it is said instead of shrugged at.
     *
     * @param list<string>          $unmapped
     * @param array<string, string> $recovered
     * @param list<string>          $unlocated classes the event log named that no candidate file declares
     *
     * @return int the number left out of the comparison
     */
    private function reportUnmapped(array $unmapped, array $recovered, array $unlocated): int
    {
        if ($unmapped === []) {
            return 0;
        }

        printf(
            '%d oracle test(s) came back without a source file — pest reports a classic PHPUnit class by '
                . 'description, not by file.' . PHP_EOL,
            count($unmapped),
        );

        if ($recovered !== []) {
            printf(
                '  Read %d of them from the run\'s own event log, which reports a class by name rather than by '
                    . 'description, and placed them by the file that declares that class.' . PHP_EOL,
                count($recovered),
            );
        }

        $lost = count($unmapped) - count($recovered);

        if ($lost <= 0) {
            print PHP_EOL;

            return 0;
        }

        printf('  %d are NOT part of what follows:' . PHP_EOL, $lost);

        foreach (array_slice($unmapped, 0, 5) as $name) {
            printf('    %s' . PHP_EOL, $name);
        }

        if (count($unmapped) > 5) {
            printf('    ... and %d more.' . PHP_EOL, count($unmapped) - 5);
        }

        if ($unlocated !== []) {
            // The residual case, and it has the opposite remedy to a
            // limit: the event log named a class, so the oracle DID run
            // it — no candidate file declares it, which means Crucible
            // ran nothing in that file. That is discovery, not a
            // structural bar, and it is said rather than shrugged at.
            printf(
                '  %d of them name a class no file Crucible ran declares, so the two runs cannot be lined up '
                    . 'there. Crucible discovering that file is what closes it — check the suites in '
                    . 'crucible.php, and ->phpunitCompatibility() if the class extends PHPUnit\'s TestCase '
                    . 'while phpunit/phpunit is installed (D-019):' . PHP_EOL,
                count($unlocated),
            );

            foreach (array_slice($unlocated, 0, 5) as $id) {
                printf('    %s' . PHP_EOL, $id);
            }
        }

        print PHP_EOL;

        return $lost;
    }

    /**
     * Why this comparison proves nothing, or null when it proves
     * something.    /**
     * Why this comparison proves nothing, or null when it proves
     * something.
     *
     * "No drift" is spelled with two empty lists — no missing tests, no
     * drifted ones — and an oracle that collected nothing produces
     * exactly that pair. The green then reports the strength of the
     * evidence as agreement, which is the one failure mode a parity
     * tool must not have: the same output for "they agree everywhere"
     * and "nothing was asked". The Crucible-side hole was already
     * guarded; this is its mirror.
     *
     * @param array<string, string> $oracle
     * @param array<string, string> $crucible
     */
    public static function nothingToCompare(array $oracle, array $crucible): ?string
    {
        if ($oracle !== []) {
            return null;
        }

        return sprintf(
            'The oracle ran no tests at all, while Crucible ran %d. Nothing can be compared, so this '
                . 'is not "no drift".' . PHP_EOL . PHP_EOL
                . 'Check that the real phpunit collects anything here on its own (vendor/bin/phpunit), that '
                . 'a forwarded --filter matches something — it is passed to both engines verbatim — and that '
                . 'this is the directory its configuration belongs to.' . PHP_EOL,
            count($crucible),
        );
    }

    /**
     * @param array<string, string>  $oracle
     * @param array<string, string>  $crucible
     * @param list<non-empty-string> $missing
     */
    private function reportMissing(array $oracle, array $crucible, array $missing): int
    {
        if ($crucible === []) {
            printf(
                'Crucible ran no tests at all, while the oracle ran %d. Nothing can be compared, so this '
                    . 'is not "no drift".' . PHP_EOL . PHP_EOL
                    . 'The usual cause: phpunit/phpunit is installed, so the PHPUnit-namespace compatibility '
                    . 'aliases stand down (D-019) and a suite extending PHPUnit\'s TestCase is discovered by '
                    . 'nobody. Give the suite Crucible\'s TestCase, opt in with ->phpunitCompatibility(), or '
                    . 'run the comparison from a checkout where the dialect applies.' . PHP_EOL,
                count($oracle),
            );

            // The setup side of the line: nothing was compared, and the
            // cause named above is a configuration one. Its neighbour
            // below stays a 1 — tests that ARE missing from a Crucible
            // run that happened is a finding about discovery, and D-112
            // is the proof that it can be a real bug.
            return 2;
        }

        printf(
            '%d test(s) the oracle passes produced no Crucible outcome at all — they were never run, which '
                . 'is not the same as passing:' . PHP_EOL,
            count($missing),
        );

        foreach (array_slice($missing, 0, 10) as $key) {
            printf('  %s' . PHP_EOL, $key);
        }

        if (count($missing) > 10) {
            printf('  ... and %d more.' . PHP_EOL, count($missing) - 10);
        }

        return 1;
    }

    /**
     * @param list<non-empty-string> $drifted
     */
    private function handleDrift(array $drifted, FixManifest $manifest, WorkingDirectory $workingDirectory, bool $autoFix, string $oracle): int
    {
        $patterns = KnownCouplingPatterns::all();
        $unfixed  = 0;

        foreach ($drifted as $key) {
            [$relativeFile, $rest] = explode('::', $key, 2);
            $method                = explode('#', $rest, 2)[0];

            printf('%s::%s fails under Crucible, passes under real %s.' . PHP_EOL, $relativeFile, $method, $oracle);
            print "  Likely coupled to PHPUnit's own internals (file layout, internal class/method names) rather than your code's behavior." . PHP_EOL;

            $file = rtrim($workingDirectory->path, '/') . '/' . $relativeFile;

            if (!is_file($file)) {
                print '  Could not locate the source file — review manually.' . PHP_EOL . PHP_EOL;
                $unfixed++;

                continue;
            }

            $source = (string) file_get_contents($file);
            $span   = SourceRewriter::locateMethod($source, $method);

            if ($span === null) {
                print '  Could not locate the method in its source file to check for a known fix — review manually.' . PHP_EOL . PHP_EOL;
                $unfixed++;

                continue;
            }

            [$startLine, $endLine] = $span;
            $preview               = SourceRewriter::preview($source, $startLine, $endLine, $patterns);

            if ($preview === []) {
                print '  No automatic fix available for this pattern — review manually, or run this specific test under PHPUnit.' . PHP_EOL . PHP_EOL;
                $unfixed++;

                continue;
            }

            foreach ($preview as $match) {
                printf('  %s (line %d):' . PHP_EOL, $match['description'], $match['line']);
                printf('    - %s' . PHP_EOL, $match['before']);
                printf('    + %s' . PHP_EOL, $match['after']);
            }

            if (!$autoFix && !$this->confirm('  Rewrite this test?')) {
                print '  Skipped.' . PHP_EOL . PHP_EOL;
                $unfixed++;

                continue;
            }

            $manifest->backup($file, $source);

            $result = SourceRewriter::apply($source, $startLine, $endLine, $patterns);
            file_put_contents($file, $result['source']);

            // "Rewrote", not "Fixed": this only confirms the known
            // pattern(s) above were applied, not that the whole test
            // now passes — the same method can carry other coupling
            // this table does not recognize (that is exactly what
            // happened to LineFormatterTest::testBasePath's second
            // assertion during this feature's own real-world
            // verification: fixing frame 0 left frame 1 still
            // failing, silently, until the mandatory re-run caught
            // it). Re-running compat-check is how success is
            // actually confirmed, not this message.
            print '  Rewrote the pattern(s) above.' . PHP_EOL . PHP_EOL;
        }

        if ($unfixed > 0) {
            printf('%d test(s) still drifted after this run. Re-run crucible compat-check to verify, or --revert to undo applied fixes.' . PHP_EOL, $unfixed);

            return 1;
        }

        print 'Rewrote every drifted test that matched a known pattern. Re-run crucible compat-check to confirm they now pass — a rewrite here is not itself proof of that.' . PHP_EOL;

        return 0;
    }

    private function revert(FixManifest $manifest): int
    {
        if ($manifest->isEmpty()) {
            print 'Nothing to revert.' . PHP_EOL;

            return 0;
        }

        $restored = $manifest->revert();

        print 'Restored:' . PHP_EOL;

        foreach ($restored as $file) {
            print '  - ' . $file . PHP_EOL;
        }

        return 0;
    }

    /**
     * @param list<string> $argv
     *
     * @return list<non-empty-string>
     */
    private function forwardedArgs(array $argv): array
    {
        $forwarded = [];

        foreach (array_slice($argv, 1) as $argument) {
            if (in_array($argument, ['compat-check', '--auto-fix', '--revert', ''], true)) {
                continue;
            }

            $forwarded[] = $argument;
        }

        return $forwarded;
    }

    private function confirm(string $prompt): bool
    {
        print $prompt . ' [y/N] ';

        $answer = fgets(STDIN);

        return $answer !== false && strtolower(trim($answer)) === 'y';
    }
}
