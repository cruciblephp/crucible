<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use LucianoPereira\Crucible\Clock\Clock;
use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Configuration\Configuration;
use LucianoPereira\Crucible\Console\Components\Spinner;
use LucianoPereira\Crucible\Coverage\CloverWriter;
use LucianoPereira\Crucible\Coverage\CoberturaWriter;
use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageData;
use LucianoPereira\Crucible\Coverage\CoverageMap;
use LucianoPereira\Crucible\Coverage\Crap4jWriter;
use LucianoPereira\Crucible\Coverage\GitInformation;
use LucianoPereira\Crucible\Coverage\HtmlReport;
use LucianoPereira\Crucible\Coverage\PhpWriter;
use LucianoPereira\Crucible\Coverage\TestLineMap;
use LucianoPereira\Crucible\Coverage\TextReport;
use LucianoPereira\Crucible\Coverage\XmlReport;
use LucianoPereira\Crucible\Event\DeprecationScope;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Flakiness\FailureOutsideDiff;
use LucianoPereira\Crucible\Impact\ChangedFiles;
use LucianoPereira\Crucible\Runner\CheckLog;
use LucianoPereira\Crucible\Runner\FlakinessLog;
use LucianoPereira\Crucible\Runner\IssueLog;
use LucianoPereira\Crucible\Runner\OutcomeLog;
use LucianoPereira\Crucible\Runner\ResultCache;

use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_string;
use function max;
use function printf;
use function realpath;
use function round;
use function sprintf;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function unlink;

use const PHP_EOL;

/**
 * The post-run reporting cluster split off `RunTestsCommand`
 * (`improvements.md` #1): everything that runs after the suite
 * finishes and only prints or computes from what already happened —
 * run-scoped check tallies, flakiness/quarantine notes, the DeFlaker
 * "outside the diff" hint, deprecation-budget breaches, coverage
 * rendering, and the exit-code policy. Kept as one class, not one per
 * method, because every method here was already independently
 * testable; the split is about getting them out of a 1,350-line file,
 * not about finding further boundaries within this cluster.
 */
final readonly class PostRunReport
{
    /**
     * Reading `time()` here bypassed the Clock the rest of the engine
     * already routes timestamps through, so two of the coverage writers
     * could not be made reproducible however the run was configured.
     * The abstraction existed; these were opted out of it.
     */
    public function __construct(private Clock $clock = new SystemClock()) {}

    /**
     * The run's instant as the coverage formats want it. Clamped at zero
     * because they type the field as non-negative and a clock can be set
     * before 1970 — the epoch `--reproducible` freezes to is exactly the
     * boundary.
     *
     * @return int<0, max>
     */
    private function stamp(): int
    {
        return max(0, $this->clock->now()->getTimestamp());
    }

    /**
     * Prints the run-scoped checks summary — a tally kept separate from
     * the tests — and returns whether any check voted the exit code. A
     * failing check carries its named reason (never a bare non-zero).
     */
    public function printChecks(CheckLog $checkLog): bool
    {
        $checks = $checkLog->checks();

        if ($checks === []) {
            return false;
        }

        $failing = $checkLog->failing();

        printf('Checks: %d run, %d failed.' . PHP_EOL, count($checks), count($failing));

        foreach ($failing as $check) {
            if ($check->reason !== null) {
                print $check->reason . PHP_EOL;
            }
        }

        return $failing !== [];
    }

    /**
     * The spec's <source> element is the coverage scope: include
     * directories (as absolute prefixes) and files, excludes removed
     * at collection by prefix.
     *
     * @param list<non-empty-string> $filter the spec's --coverage-filter
     *
     * @return list<non-empty-string>
     */
    public function coverageScope(Configuration $configuration, WorkingDirectory $workingDirectory, array $filter = []): array
    {
        $scope = [];

        // The spec's --coverage-filter reads "include <dir> in code
        // coverage reporting", so it adds to the configured scope
        // rather than replacing it — and with no <source> configured it
        // is the whole scope, which is the bare "just this directory"
        // use the option exists for.
        foreach ($filter as $directory) {
            $absolute = realpath($workingDirectory->absolute($directory));

            if ($absolute !== false) {
                $scope[] = is_dir($absolute) ? $absolute . '/' : $absolute;
            }
        }

        foreach ($configuration->source->includeDirectories as $directory) {
            $absolute = realpath($workingDirectory->absolute($directory));

            if ($absolute !== false) {
                $scope[] = $absolute . '/';
            }
        }

        foreach ($configuration->source->includeFiles as $file) {
            $absolute = realpath($workingDirectory->absolute($file));

            if ($absolute !== false) {
                $scope[] = $absolute;
            }
        }

        return $scope;
    }

    /**
     * Folds every artifact matching a glob into the collection and
     * removes it, returning how many were merged. Unreadable is
     * skipped rather than fatal, and still removed: an artifact nobody
     * can read is not evidence, and leaving it would have every later
     * run try again.
     *
     * @return int how many artifacts contributed
     */
    private function mergeArtifacts(CoverageData $data, string $pattern): int
    {
        $artifacts = glob($pattern);
        $merged    = 0;

        foreach ($artifacts === false ? [] : $artifacts as $artifact) {
            $contents = file_get_contents($artifact);

            if ($contents !== false) {
                $data->merge(CoverageData::fromJson($contents));
                $merged++;
            }

            unlink($artifact);
        }

        return $merged;
    }

    /**
     * After the run: merge worker and external artifacts into the
     * in-process collection, render the requested reports, and refresh
     * the observed-edges map the impact graph reads (D-041).
     *
     * @param non-empty-string $cacheDirectory
     * @param list<array{id: string, status: string, time: float, size?: string}> $tests
     *                                                                                   how each test ended, for the XML index; the other writers have nowhere to put it
     *
     * @return CoverageData the merged collection, so the caller can judge it against a floor
     */
    public function reportCoverage(CoverageCollector $collector, CliOptions $options, WorkingDirectory $workingDirectory, string $cacheDirectory, array $tests = []): CoverageData
    {
        $data  = $collector->data();
        $cache = $workingDirectory->absolute($cacheDirectory);

        // Two directories because the two lifetimes differ. A worker's
        // artifact belongs to this run and is swept as stale before the
        // run starts; an external one is written deliberately BEFORE a
        // run, by another process under its own driver, and that sweep
        // would delete it every time. Both are consumed exactly once.
        $this->mergeArtifacts($data, $cache . '/coverage.tmp/*.json');
        $external = $this->mergeArtifacts($data, $cache . '/coverage.external/*.json');

        if ($external > 0) {
            // Folding another process's work in silently would leave the
            // total describing more than the run it prints under.
            printf('Merged %d coverage artifact(s) from processes outside this run.' . PHP_EOL, $external);
        }

        $textReport = new TextReport($options->onlySummaryForCoverageText, $options->showUncoveredForCoverageText);

        if ($options->coverage || $options->coverageBranch) {
            print PHP_EOL . $textReport->render($data, $workingDirectory->path, $collector->driverName());
        }

        // --coverage-text is the same report to a chosen sink: bare
        // means the terminal, which is the spec's documented default.
        if ($options->coverageText !== null) {
            $rendered = $textReport->render($data, $workingDirectory->path, $collector->driverName());

            if ($options->coverageText === '') {
                print PHP_EOL . $rendered;
            } else {
                file_put_contents($workingDirectory->absolute($options->coverageText), $rendered);
                printf('Text coverage written to %s.' . PHP_EOL, $options->coverageText);
            }
        }

        if ($options->coverageOpenClover !== null) {
            file_put_contents(
                $workingDirectory->absolute($options->coverageOpenClover),
                (new CloverWriter(openClover: true))->write($data, $this->stamp()),
            );

            printf('OpenClover coverage written to %s.' . PHP_EOL, $options->coverageOpenClover);
        }

        if ($options->coveragePhp !== null) {
            file_put_contents(
                $workingDirectory->absolute($options->coveragePhp),
                (new PhpWriter())->write($data, $collector->driverName(), $options->includeGitInformation ? GitInformation::read($workingDirectory) : null, $this->clock->now()),
            );

            printf('Serialized coverage written to %s.' . PHP_EOL, $options->coveragePhp);
        }

        if ($options->coverageClover !== null) {
            file_put_contents(
                $workingDirectory->absolute($options->coverageClover),
                (new CloverWriter())->write($data, $this->stamp()),
            );

            printf('Clover coverage written to %s.' . PHP_EOL, $options->coverageClover);
        }

        if ($options->coverageCobertura !== null) {
            file_put_contents(
                $workingDirectory->absolute($options->coverageCobertura),
                (new CoberturaWriter())->write($data, $workingDirectory->path, $this->stamp()),
            );

            printf('Cobertura coverage written to %s.' . PHP_EOL, $options->coverageCobertura);
        }

        if ($options->coverageCrap4j !== null) {
            file_put_contents(
                $workingDirectory->absolute($options->coverageCrap4j),
                (new Crap4jWriter())->write($data, $workingDirectory->path),
            );

            printf('Crap4J coverage written to %s.' . PHP_EOL, $options->coverageCrap4j);
        }

        if ($options->coverageXml !== null) {
            (new XmlReport($options->excludeSourceFromXmlCoverage))->write(
                $data,
                $workingDirectory->path,
                $workingDirectory->absolute($options->coverageXml),
                $collector->driverName(),
                $tests,
            );

            printf('XML coverage written to %s/index.xml.' . PHP_EOL, $options->coverageXml);
        }

        if ($options->coverageHtml !== null) {
            // Annotating every source file is the longest silence a run
            // has after the suite itself, and it happens once the tally
            // has already printed — so it reads as a hang.
            (new Spinner('Rendering the HTML coverage report'))->spin(
                function () use ($options, $data, $workingDirectory, $collector): void {
                    (new HtmlReport($options->withoutClassView, $options->withoutFileView))->write(
                        $data,
                        $workingDirectory->path,
                        $workingDirectory->absolute($options->coverageHtml),
                        $collector->driverName(),
                    );
                },
            );

            printf('HTML coverage written to %s/index.html.' . PHP_EOL, $options->coverageHtml);
        }

        CoverageMap::save(
            $workingDirectory->absolute($cacheDirectory) . '/coverage-map.json',
            CoverageMap::fromData($data, $workingDirectory->path),
        );

        // The per-test line map (D-047): the mutation query's data,
        // refreshed beside the impact map on every coverage run.
        TestLineMap::fromData($data, $workingDirectory->path)
            ->save($workingDirectory->absolute($cacheDirectory) . '/coverage-lines.json');

        return $data;
    }

    /**
     * The coverage floor (`--min`): a breach message when line coverage
     * came in under the percentage asked for, null when it did not.
     *
     * The number judged is the one the text report *prints* — rounded to
     * two places — not the raw quotient. A gate that failed a run whose
     * report reads `Total: 90.00%` against `--min=90` would be arguing
     * with the user about a third decimal they cannot see.
     *
     * An empty scope is a breach rather than a pass. Zero executable
     * lines means the run measured nothing, and a gate that reports
     * success when it measured nothing is the failure mode the gate
     * exists to prevent.
     *
     * @return ?non-empty-string
     */
    public function coverageFloor(CoverageData $data, ?float $minimum): ?string
    {
        if ($minimum === null) {
            return null;
        }

        // A floor of zero demands nothing, so nothing can fail it. The
        // empty-scope rule below is about an ask that CANNOT be met;
        // this one is met by anything, including nothing measured.
        if ($minimum <= 0.0) {
            return null;
        }

        $totals = $data->totals();

        if ($totals['executable'] === 0) {
            return 'Coverage floor cannot be judged: the coverage scope contains no executable lines.';
        }

        $percent = round(100 * $totals['covered'] / $totals['executable'], 2);

        if ($percent >= $minimum) {
            return null;
        }

        return sprintf(
            'Coverage below the required minimum: %.2f%% of %d executable lines covered, at least %s%% required.',
            $percent,
            $totals['executable'],
            $minimum,
        );
    }

    /**
     * The G4 post-run notes: name what was flaky, what quarantine
     * absorbed, and which quarantined tests look ready for release.
     */
    public function printFlakiness(FlakinessLog $flakiness): void
    {
        if ($flakiness->flakyCount() > 0) {
            printf(PHP_EOL . 'Flaky: %d test(s) passed only on retry:' . PHP_EOL, $flakiness->flakyCount());

            foreach ($flakiness->flakyTests() as $id) {
                print '  - ' . $id . PHP_EOL;
            }
        }

        if ($flakiness->quarantinedFailureCount() > 0) {
            printf(PHP_EOL . 'Quarantined: %d failing test(s) did not affect the result:' . PHP_EOL, $flakiness->quarantinedFailureCount());

            foreach ($flakiness->quarantinedFailures() as $id) {
                print '  - ' . $id . PHP_EOL;
            }
        }

        if ($flakiness->quarantinedPasses() !== []) {
            print PHP_EOL . 'Quarantined but passing — candidates for release:' . PHP_EOL;

            foreach ($flakiness->quarantinedPasses() as $id) {
                print '  - ' . $id . PHP_EOL;
            }
        }
    }

    /**
     * The DeFlaker hint (D-048): a fresh failure that executed none
     * of the changed code was probably not caused by the change.
     * Advisory only — it never touches outcomes or the exit code, and
     * every missing ingredient (no failures, no line map from a prior
     * coverage run, no git answer, no comparable diff) is silence.
     *
     * @param non-empty-string $cacheDirectory
     */
    public function printFailuresOutsideDiff(
        FlakinessLog $flakiness,
        ResultCache $cache,
        Configuration $configuration,
        CliOptions $options,
        WorkingDirectory $workingDirectory,
        string $cacheDirectory,
    ): void {
        if ($flakiness->failures() === []) {
            return;
        }

        // Only fresh failures qualify (DeFlaker's "new failure"): a
        // test that already failed last run was broken before this
        // diff existed, and the diff can be neither blamed nor cleared.
        $fresh = [];

        foreach ($flakiness->failures() as $id) {
            $previous = $cache->outcomes($id)[1] ?? null;

            if ($previous !== Outcome::Failed && $previous !== Outcome::Errored) {
                $fresh[] = $id;
            }
        }

        if ($fresh === []) {
            return;
        }

        $map = TestLineMap::load($workingDirectory->absolute($cacheDirectory) . '/coverage-lines.json');

        if ($map->tests === []) {
            return;
        }

        $reference = $options->changed ?? 'HEAD';
        $changed   = ChangedFiles::fromGit($workingDirectory, $reference);

        if (is_string($changed)) {
            return;
        }

        // Deleted PHP is code the map may still reference but no run
        // can observe; changed PHP outside the project the same. Both
        // make "executed no changed code" unanswerable.
        foreach ($changed->deleted as $name) {
            if (str_ends_with($name, '.php')) {
                return;
            }
        }

        $prefix     = $workingDirectory->path . '/';
        $changedPhp = [];

        foreach ($changed->files as $absolute) {
            if (!str_ends_with($absolute, '.php')) {
                continue;
            }

            if (!str_starts_with($absolute, $prefix)) {
                return;
            }

            $relative = substr($absolute, strlen($prefix));

            if ($relative !== '') {
                $changedPhp[] = $relative;
            }
        }

        $scope = [];

        foreach ($this->coverageScope($configuration, $workingDirectory) as $absoluteScope) {
            if (str_starts_with($absoluteScope, $prefix)) {
                $relative = substr($absoluteScope, strlen($prefix));

                if ($relative !== '') {
                    $scope[] = $relative;
                }
            }
        }

        $suspects = (new FailureOutsideDiff($map))->suspects($fresh, $changedPhp, $scope);

        if ($suspects === []) {
            return;
        }

        printf(
            PHP_EOL . 'Outside the diff: %d fresh failure(s) executed none of the changed files (against %s) — likely flaky or environmental, not this change. `crucible flakes` classifies:' . PHP_EOL,
            count($suspects),
            $reference,
        );

        foreach ($suspects as $id) {
            print '  - ' . $id . PHP_EOL;
        }
    }


    /**
     * The spec's --display-* family: what the tally counted, listed.
     *
     * The run summary says "Deprecations: 3"; these say which three, where
     * they were triggered, and — for a deprecation — whether it came from
     * the project's own code, from a dependency it called, or from one
     * dependency calling another.
     */
    public function printIssueDisplays(CliOptions $options, IssueLog $issueLog, OutcomeLog $outcomes): void
    {
        $kinds = array_filter([
            IssueKind::Deprecation->value => $options->displayDeprecations,
            IssueKind::Notice->value      => $options->displayNotices,
            IssueKind::Warning->value     => $options->displayWarnings,
        ]);

        foreach (array_keys($kinds) as $kind) {
            $matching = array_values(array_filter(
                $issueLog->issues(),
                static fn(Issue $issue): bool => $issue->kind->value === $kind,
            ));

            if ($matching === []) {
                continue;
            }

            printf(PHP_EOL . '%d %s(s):' . PHP_EOL, count($matching), $kind);

            foreach ($matching as $issue) {
                printf(
                    '  %s:%d  %s%s' . PHP_EOL,
                    $issue->file,
                    $issue->line,
                    $issue->message,
                    $issue->scope instanceof DeprecationScope ? ' [' . $issue->scope->value . ']' : '',
                );
            }
        }

        $listings = array_filter([
            'errored'    => $options->displayErrors,
            'skipped'    => $options->displaySkipped,
            'incomplete' => $options->displayIncomplete,
        ]);

        foreach (array_keys($listings) as $name) {
            $outcome = Outcome::from($name === 'errored' ? 'error' : ($name === 'skipped' ? 'skip' : 'incomplete'));
            $tests   = $outcomes->of($outcome);

            if ($tests === []) {
                continue;
            }

            printf(PHP_EOL . '%d %s test(s):' . PHP_EOL, count($tests), $name);

            foreach ($tests as $test) {
                printf('  %s%s' . PHP_EOL, $test['id'], $test['reason'] !== null ? '  ' . $test['reason'] : '');
            }
        }
    }

    /**
     * The per-scope deprecation budgets (DESIGN.md D-026): typed form
     * of the Symfony bridge's max[self|direct|indirect] grammar.
     *
     * @return list<non-empty-string>
     */
    public function thresholdBreaches(Configuration $configuration, IssueLog $issueLog): array
    {
        $scopes   = $issueLog->deprecationsByScope();
        $breaches = [];

        $budgets = [
            'self'     => $configuration->maxSelfDeprecations,
            'direct'   => $configuration->maxDirectDeprecations,
            'indirect' => $configuration->maxIndirectDeprecations,
        ];

        foreach ($budgets as $scope => $budget) {
            $count = $scopes[$scope] ?? 0;

            if ($budget !== null && $count > $budget) {
                $breaches[] = sprintf(
                    'Deprecation budget exceeded for scope "%s": %d observed, at most %d allowed.',
                    $scope,
                    $count,
                    $budget,
                );
            }
        }

        return $breaches;
    }

    public function exitCode(RunSummary $summary, Configuration $configuration, CliOptions $options, FlakinessLog $flakiness = new FlakinessLog(), IssueLog $issueLog = new IssueLog()): int
    {
        // Quarantined failures are reported but never judged (G4) —
        // subtract them before the verdict.
        $errored = $summary->errored - $flakiness->quarantinedErrorCount();
        $failed  = $summary->failed - ($flakiness->quarantinedFailureCount() - $flakiness->quarantinedErrorCount());

        if ($errored > 0) {
            return 2;
        }

        $failOnRisky       = $options->failOnRisky ?? $configuration->failOnRisky;
        $failOnSkipped     = $options->failOnSkipped ?? $configuration->failOnSkipped;
        $failOnIncomplete  = $options->failOnIncomplete ?? $configuration->failOnIncomplete;
        $failOnDeprecation = $options->failOnDeprecation ?? $configuration->failOnDeprecation;
        $failOnNotice      = $options->failOnNotice ?? $configuration->failOnNotice;
        $failOnWarning     = $options->failOnWarning ?? $configuration->failOnWarning;

        // The scoped deprecation policies read the attribution the
        // collector already made (DeprecationScope): a trigger in the
        // project's own code is self, one in a dependency is direct when
        // project code called it and indirect otherwise. Same counts the
        // D-026 budgets use, judged as a yes/no rather than a ceiling.
        $scopes = $issueLog->deprecationsByScope();

        $failOnScope = static fn(string $scope, ?bool $cli, bool $configured): bool => ($cli ?? $configured)
            && ($scopes[$scope] ?? 0) > 0;

        $failedByPolicy = ($failOnRisky && $summary->risky > 0)
            || ($failOnSkipped && $summary->skipped > 0)
            || ($failOnIncomplete && $summary->incomplete > 0)
            || ($failOnDeprecation && $summary->deprecations > 0)
            || ($failOnNotice && $summary->notices > 0)
            || ($failOnWarning && $summary->warnings > 0)
            || $failOnScope('self', $options->failOnSelfDeprecation, $configuration->failOnSelfDeprecation)
            || $failOnScope('direct', $options->failOnDirectDeprecation, $configuration->failOnDirectDeprecation)
            || $failOnScope('indirect', $options->failOnIndirectDeprecation, $configuration->failOnIndirectDeprecation)
            || (($options->failOnEmptyTestSuite ?? $configuration->failOnEmptyTestSuite) && $summary->total() === 0)
            || ($options->failOnFlaky === true && $flakiness->flakyCount() > 0);

        if ($failed > 0 || $failedByPolicy) {
            return 1;
        }

        return 0;
    }
}
