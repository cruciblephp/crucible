<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI\Commands;

use Closure;
use LucianoPereira\Crucible\Attributes\MutatesClass;
use LucianoPereira\Crucible\CLI\CliOptions;
use LucianoPereira\Crucible\Configuration\Loader;
use LucianoPereira\Crucible\Console\Components\Progress;
use LucianoPereira\Crucible\Coverage\MutationIndex;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Dialect\PhpUnit\ClassLocator;
use LucianoPereira\Crucible\Exceptions\Exception;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Mutation\ColdMutantExecutor;
use LucianoPereira\Crucible\Mutation\CoveringTestRunner;
use LucianoPereira\Crucible\Mutation\Mutant;
use LucianoPereira\Crucible\Mutation\MutantApplier;
use LucianoPereira\Crucible\Mutation\MutantGenerator;
use LucianoPereira\Crucible\Mutation\MutationJournal;
use LucianoPereira\Crucible\Mutation\MutationOutcome;
use LucianoPereira\Crucible\Mutation\MutationReport;
use LucianoPereira\Crucible\Mutation\MutationRunner;
use LucianoPereira\Crucible\Mutation\MutationVerdict;
use LucianoPereira\Crucible\Mutation\WarmMutantExecutor;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\TestDiscoverer;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_filter;
use function array_keys;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function file_get_contents;
use function filemtime;
use function implode;
use function intdiv;
use function is_file;
use function json_encode;
use function ksort;
use function md5;
use function microtime;
use function printf;
use function realpath;
use function rtrim;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;

/**
 * The `crucible mutation-index` and `crucible mutate` commands
 * (D-077, D-082): the per-line covering-tests index, and the
 * mutation-testing run built on top of it.
 */
final class MutationCommand
{
    /**
     * `crucible mutation-index` (D-077): emit the mutation-facing query
     * index as JSON — for every covered source line, the tests that ran
     * it, fastest-first. Assembled from the per-test line map
     * (`--coverage`) and the result cache's timings; a mutation tool
     * loads it to run a mutant's covering tests cheapest-first.
     *
     */
    public function index(CliOptions $options, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $cacheDirectory = $options->cacheDirectory ?? $loaded->configuration->cacheDirectory;
        $base           = $workingDirectory->absolute($cacheDirectory);
        $map            = TestLineMap::load($base . '/coverage-lines.json');

        if ($map->tests === []) {
            // An opt-in query owes the user the way in (D-041's rule).
            print 'mutation-index needs a per-test line map — run the suite once with --coverage first.' . PHP_EOL;

            return 1;
        }

        $cache     = ResultCache::load($base . '/results.json');
        $durations = [];

        foreach (array_keys($map->tests) as $id) {
            if ($id === '') {
                continue;
            }

            $duration = $cache->duration($id);

            if ($duration !== null) {
                $durations[$id] = $duration;
            }
        }

        print json_encode(
            MutationIndex::build($map, $durations)->toArray(),
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        ) . PHP_EOL;

        return 0;
    }

    /**
     * `crucible mutate`: generate mutants across the covered source, run each
     * against its covering tests (fastest-first, D-077), and report the
     * mutation score. Cold execution — portable, no pcntl — in v1; the
     * warm fork is the follow-up the router already accommodates. Exits 1
     * when any mutant escaped: an escaped mutant is a gap in the suite.
     *
     * @param list<string>     $argv
     */
    public function mutate(CliOptions $options, array $argv, WorkingDirectory $workingDirectory): int
    {
        try {
            $loaded = (new Loader())->load($workingDirectory, $options->configuration);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $cacheDirectory = $options->cacheDirectory ?? $loaded->configuration->cacheDirectory;
        $base           = $workingDirectory->absolute($cacheDirectory);
        $mapFile        = $base . '/coverage-lines.json';
        $map            = TestLineMap::load($mapFile);

        if ($map->tests === []) {
            print 'mutate needs a per-test line map — run the suite once with --coverage first.' . PHP_EOL;

            return 1;
        }

        $cache     = ResultCache::load($base . '/results.json');
        $durations = [];

        foreach (array_keys($map->tests) as $id) {
            if ($id === '') {
                continue;
            }

            $duration = $cache->duration($id);

            if ($duration !== null) {
                $durations[$id] = $duration;
            }
        }

        $index   = MutationIndex::build($map, $durations);
        $covered = array_keys($index->toArray());

        $this->warnIfCoverageStale($mapFile, $covered, $workingDirectory);

        // Discovery is hoisted above mutant generation because mutates()
        // lives in test metadata, and the warm executor below needs the
        // same discovered set — one discovery serves both.
        try {
            $discovered = (new TestDiscoverer())->discover($loaded->configuration, $workingDirectory);
        } catch (Exception $e) {
            print $e->getMessage() . PHP_EOL;

            return 1;
        }

        $declared = $this->declaredMutationScope($discovered);
        $covered  = $this->narrowToDeclaredMutationScope($declared, $covered, $workingDirectory);

        // Asked to mutate named classes and none of them is covered: the
        // ask cannot be met. Reporting "no mutants" and exiting 0 here
        // would say the mutation run succeeded having tested nothing,
        // which is the silent pass this command exists to prevent.
        if ($declared !== [] && $covered === []) {
            printf(
                'mutates(): none of the declared classes is covered, so there is nothing to mutate: %s' . PHP_EOL,
                implode(', ', array_keys($declared)),
            );

            return 1;
        }

        $mutants = $this->generateMutants($covered, $workingDirectory);
        $binary  = realpath($argv[0] ?? '');

        if ($mutants === []) {
            print 'No mutants generated from the covered source.' . PHP_EOL;

            return 0;
        }

        if ($binary === false) {
            print 'Cannot resolve the crucible binary path for the cold mutation workers.' . PHP_EOL;

            return 1;
        }

        // Resume before anything is executed. The journal is keyed on
        // mutated content, so a source edit drops its own entries and
        // only the untouched files carry over.
        $journal     = MutationJournal::load($base . '/mutation-verdicts.ndjson');
        $coveringIds = function (Mutant $mutant) use ($index, $workingDirectory): array {
            $relative = $this->relativeTo($mutant->file, $workingDirectory);
            $ids      = [];

            foreach ($index->coveringTests($relative, $mutant->line) as $test) {
                if ($test['id'] !== '') {
                    $ids[] = $test['id'];
                }
            }

            return $ids;
        };

        $sources  = [];
        $judgedBy = [];
        $done     = [];
        $pending  = [];

        foreach ($mutants as $mutant) {
            $fingerprint                               = $this->judgedBy($coveringIds($mutant), $workingDirectory, $sources);
            $judgedBy[MutationJournal::keyOf($mutant)] = $fingerprint;

            if ($journal->has($mutant, $fingerprint)) {
                $done[] = $mutant;

                continue;
            }

            $pending[] = $mutant;
        }

        printf('Mutation testing: %d mutant(s) across %d covered file(s).' . PHP_EOL, count($mutants), count($covered));

        if ($done !== []) {
            printf(
                'Resuming: %d already decided, %d to run. Delete %s to start over.' . PHP_EOL,
                count($done),
                count($pending),
                $this->relativeTo($base . '/mutation-verdicts.ndjson', $workingDirectory),
            );
        }

        print PHP_EOL;

        // Warm forking where the platform allows it; the suite is discovered
        // here in the parent, which never runs a test — so the source under
        // mutation stays unloaded and every mutant is warm-applicable. A
        // box without pcntl has no warm executor and routes every mutant
        // cold (the router's contract). D-082.
        $warm = null;

        if (MutantApplier::isSupported()) {
            $covering = new CoveringTestRunner($discovered, $workingDirectory, $cache);
            $warm     = new WarmMutantExecutor(new MutantApplier(), $covering->firstKiller(...));
        }

        $cold = new ColdMutantExecutor($binary, $loaded->path, $workingDirectory);

        // The bar counts the whole run, resumed mutants included, so a
        // resumed run does not restart at 0%.
        $bar          = new Progress('Mutation testing', count($mutants));
        $bar->current = count($done);
        $bar->start();

        (new MutationRunner($cold, $warm, $coveringIds))
            ->run($pending, $this->progress($bar, $workingDirectory, $journal, $judgedBy, count($done), count($mutants)));

        $bar->finish();

        // The report is the WHOLE run, read back from the journal: the
        // verdicts this process just appended plus the ones an earlier
        // one did. Scoring $report alone would rate a resumed run on
        // only the mutants it happened to finish.
        return $this->printMutationReport(new MutationReport($journal->verdictsFor($mutants)), $workingDirectory);
    }

    /**
     * The classes the suite declared with mutates(), if any.
     *
     * @param list<TestGroup> $discovered
     *
     * @return array<class-string, true>
     */
    private function declaredMutationScope(array $discovered): array
    {
        $declared = [];

        foreach ($discovered as $group) {
            foreach ($group->tests as $test) {
                foreach ($test->metadata->ofType(MutatesClass::class) as $mutates) {
                    $declared[$mutates->className] = true;
                }
            }
        }

        return $declared;
    }

    /**
     * Narrow the covered source to what mutates() declared, if anything did.
     *
     * Declaring nothing keeps the whole covered scope: a suite that never
     * says mutates() must not silently mutate nothing.
     *
     * @param array<class-string, true> $declared
     * @param list<string>              $covered  relative paths
     *
     * @return list<string>
     */
    private function narrowToDeclaredMutationScope(array $declared, array $covered, WorkingDirectory $workingDirectory): array
    {
        if ($declared === []) {
            return $covered;
        }

        // Compare file -> class rather than resolving class -> file: the
        // covered files are already in hand, and this needs no autoload
        // of the declared target.
        $locator  = new ClassLocator();
        $narrowed = array_values(array_filter(
            $covered,
            static function (string $relative) use ($locator, $declared, $workingDirectory): bool {
                $class = $locator->classIn($workingDirectory->absolute($relative));

                return $class !== null && isset($declared[$class]);
            },
        ));

        printf(
            'mutates(): narrowed the mutation scope to %d of %d covered file(s).' . PHP_EOL,
            count($narrowed),
            count($covered),
        );

        return $narrowed;
    }

    /**
     * Generate mutants over the covered source files (relative paths),
     * resolving each file's class as the autoload key. Files without a
     * declared class, or that cannot be read, are skipped.
     *
     * @param list<string>     $coveredFiles relative paths
     *
     * @return list<Mutant>
     */
    private function generateMutants(array $coveredFiles, WorkingDirectory $workingDirectory): array
    {
        $generator = new MutantGenerator();
        $locator   = new ClassLocator();
        $mutants   = [];

        foreach ($coveredFiles as $relative) {
            if ($relative === '') {
                continue;
            }

            $absolute = $workingDirectory->absolute($relative);

            // A covered "file" can be an eval()'d-code pseudo-path (inline
            // doctests) — not a real file to mutate.
            if (!is_file($absolute)) {
                continue;
            }

            $source = @file_get_contents($absolute);
            $class  = $locator->classIn($absolute);

            if ($source === false || $class === null) {
                continue;
            }

            foreach ($generator->generate($absolute, $class, $source) as $mutant) {
                $mutants[] = $mutant;
            }
        }

        return $mutants;
    }

    /**
     * Coverage that predates the source it maps is a lie — an escaped
     * mutant might just be a covering test that no longer runs. Warn (not
     * refuse: the user may know better) and name the drift.
     *
     * @param list<string>     $coveredFiles relative paths
     */
    private function warnIfCoverageStale(string $mapFile, array $coveredFiles, WorkingDirectory $workingDirectory): void
    {
        $coverageTime = @filemtime($mapFile);

        if ($coverageTime === false) {
            return;
        }

        $stale = [];

        foreach ($coveredFiles as $relative) {
            if ($relative === '') {
                continue;
            }

            $sourceTime = @filemtime($workingDirectory->absolute($relative));

            if ($sourceTime !== false && $sourceTime > $coverageTime) {
                $stale[] = $relative;
            }
        }

        if ($stale !== []) {
            printf(
                'Warning: %d covered file(s) changed since coverage was collected — results may be inaccurate; re-run with --coverage. First: %s' . PHP_EOL . PHP_EOL,
                count($stale),
                $stale[0],
            );
        }
    }

    /**
     * Live output for a run measured in hours.
     *
     * Escapes print the moment they are found rather than at the end,
     * because an escape is the finding — a run stopped halfway has still
     * told the user which mutants their suite does not catch. The
     * counter is throttled to once a second: 4083 lines of progress is
     * not progress.
     *
     * Every verdict is journalled here too, before anything is printed:
     * the point of the journal is to survive the kill, so it must be
     * written at the moment the verdict exists, not at the end.
     *
     * @param array<string, string> $judgedBy         mutant key => covering-tests fingerprint
     * @param int                   $resumed          verdicts an earlier run already decided
     * @param int                   $overall          mutants in the whole run, resumed included
     *
     * @return Closure(MutationVerdict, int, int): void
     */
    private function progress(Progress $bar, WorkingDirectory $workingDirectory, MutationJournal $journal, array $judgedBy, int $resumed, int $overall): Closure
    {
        $started = microtime(true);
        $last    = 0.0;
        $escaped = 0;

        return function (MutationVerdict $verdict, int $done, int $total) use ($bar, $workingDirectory, $journal, $judgedBy, $resumed, $overall, $started, &$last, &$escaped): void {
            $journal->append($verdict->mutant, $verdict, $judgedBy[MutationJournal::keyOf($verdict->mutant)] ?? '');

            // $done counts THIS process; the rate must too. Dividing the
            // elapsed time of a resumed run by a count that includes the
            // resumed mutants reads as a machine several times faster
            // than the one doing the work.
            $rate  = $done === 0 ? 0.0 : (microtime(true) - $started) / $done;
            $done  = $resumed + $done;
            $total = $overall;

            $bar->current = $done;

            if ($verdict->outcome === MutationOutcome::Escaped) {
                $escaped++;

                // Through the bar, not print: an escape is history and has
                // to scroll away above a frame that stays put. Without a
                // bar on screen this is the same line it always was.
                $bar->interrupt(sprintf(
                    '  ESCAPED  %s:%d  %s',
                    $this->relativeTo($verdict->mutant->file, $workingDirectory),
                    $verdict->mutant->line,
                    $verdict->mutant->mutatorId,
                ));
            }

            $now = microtime(true);

            if ($now - $last < 1.0 && $done !== $total) {
                return;
            }

            $last    = $now;
            $elapsed = $now - $started;
            $left    = $rate * ($total - $done);

            // ⚠ The piped form is unchanged, deliberately: a `tee`d
            // mutation log is how a run that takes hours gets read back,
            // and these are the lines that get grepped. The bar is what
            // replaces them at a terminal, where nothing is being kept.
            if (! $bar->isActive()) {
                printf(
                    '  %d/%d mutants — %d escaped — %s elapsed, ~%s left' . PHP_EOL,
                    $done,
                    $total,
                    $escaped,
                    $this->duration($elapsed),
                    $this->duration($left),
                );

                return;
            }

            $bar->hint(sprintf(
                '%d/%d · %d escaped · %s elapsed, ~%s left',
                $done,
                $total,
                $escaped,
                $this->duration($elapsed),
                $this->duration($left),
            ));
        };
    }

    /**
     * A fingerprint of the tests that will judge this mutant.
     *
     * Their CONTENTS, not their names: rewriting a test's assertions
     * without renaming it is exactly the change that must invalidate a
     * recorded verdict, and it is the change that slipped through when
     * the journal keyed on the mutant alone.
     *
     * Per FILE rather than per test id — a test id is `file.php::method`
     * and a file's methods share its bytes — with the digests cached
     * across mutants, since one test file covers hundreds of them.
     *
     * @param list<non-empty-string> $testIds
     * @param array<string, string>  $sources   digest cache, by file
     */
    private function judgedBy(array $testIds, WorkingDirectory $workingDirectory, array &$sources): string
    {
        $digests = [];

        foreach ($testIds as $id) {
            $file = explode('::', $id)[0];

            if ($file === '') {
                continue;
            }

            if (!isset($sources[$file])) {
                $contents       = @file_get_contents($workingDirectory->absolute($file));
                $sources[$file] = $contents === false ? '' : md5($contents);
            }

            $digests[$file] = $sources[$file];
        }

        ksort($digests);

        return md5(implode('|', $digests) . '#' . implode('|', array_keys($digests)));
    }

    /** @return non-empty-string */
    private function duration(float $seconds): string
    {
        $whole = (int) $seconds;

        return $whole < 60
            ? $whole . 's'
            : sprintf('%dm%02ds', intdiv($whole, 60), $whole % 60);
    }

    private function printMutationReport(MutationReport $report, WorkingDirectory $workingDirectory): int
    {
        $escaped = array_values(array_filter(
            $report->verdicts,
            static fn(MutationVerdict $verdict): bool => $verdict->outcome === MutationOutcome::Escaped,
        ));

        foreach (array_slice($escaped, 0, 20) as $verdict) {
            printf('  ESCAPED  %s:%d  %s' . PHP_EOL, $this->relativeTo($verdict->mutant->file, $workingDirectory), $verdict->mutant->line, $verdict->mutant->mutatorId);
        }

        if (count($escaped) > 20) {
            printf('  … and %d more escaped.' . PHP_EOL, count($escaped) - 20);
        }

        if ($escaped !== []) {
            print PHP_EOL;
        }

        printf(
            'Killed: %d  Escaped: %d  Errored: %d  Timed out: %d  Not covered: %d' . PHP_EOL,
            $report->count(MutationOutcome::Killed),
            count($escaped),
            $report->count(MutationOutcome::Errored),
            $report->count(MutationOutcome::TimedOut),
            $report->count(MutationOutcome::NotCovered),
        );
        printf('MSI: %.1f%% over %d covered mutant(s).' . PHP_EOL, $report->score(), $report->covered());

        return $escaped === [] ? 0 : 1;
    }

    private function relativeTo(string $file, WorkingDirectory $workingDirectory): string
    {
        $prefix = rtrim($workingDirectory->path, '/') . '/';

        return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
    }
}
