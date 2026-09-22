<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use Closure;
use LucianoPereira\Crucible\Assert\Assert;
use LucianoPereira\Crucible\Assert\AssertionFailedError;
use LucianoPereira\Crucible\Attributes\BackupGlobals;
use LucianoPereira\Crucible\Attributes\BackupStaticProperties;
use LucianoPereira\Crucible\Attributes\DoesNotPerformAssertions;
use LucianoPereira\Crucible\Attributes\ExcludeGlobalVariableFromBackup;
use LucianoPereira\Crucible\Attributes\ExcludeStaticPropertyFromBackup;
use LucianoPereira\Crucible\Attributes\ExpectedOutcome;
use LucianoPereira\Crucible\Attributes\Quarantined;
use LucianoPereira\Crucible\Attributes\Retry;
use LucianoPereira\Crucible\Attributes\Todo;
use LucianoPereira\Crucible\Browser\Browsing;
use LucianoPereira\Crucible\Coverage\CoverageCollector;
use LucianoPereira\Crucible\Coverage\CoverageWindow;
use LucianoPereira\Crucible\Coverage\CoversTargets;
use LucianoPereira\Crucible\Double\Mockery\MockeryContainer;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\Issue;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\OutputChannel;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestOutputWritten;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Framework\IncompleteTestError;
use LucianoPereira\Crucible\Framework\SkippedTestError;
use LucianoPereira\Crucible\Isolation\GlobalStateSnapshot;
use LucianoPereira\Crucible\Isolation\StaticRegistry;
use LucianoPereira\Crucible\Metadata\MetadataCollection;
use LucianoPereira\Crucible\Property\PropertyContext;
use LucianoPereira\Crucible\Property\PropertyFailedError;
use LucianoPereira\Crucible\Runner\Process\DependencyValues;
use LucianoPereira\Crucible\Snapshot\SnapshotRepository;
use LucianoPereira\Crucible\Snapshot\Snapshots;
use LucianoPereira\Crucible\Test\Requirements;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use Throwable;

use function array_any;
use function array_map;
use function count;
use function hrtime;
use function implode;
use function in_array;
use function ob_get_clean;
use function ob_start;
use function sprintf;
use function str_contains;
use function trim;
use function usleep;

/**
 * Walking-skeleton runner: sequential, in-process, single-threaded.
 * The supervisor/worker pool with isolation and scheduling replaces
 * the internals in Phase 6; the outcome classification and event
 * protocol here are the stable part.
 */
final readonly class TestRunner
{
    private StaticRegistry $statics;

    private IssueCollector $issues;

    public function __construct(
        private Emitter $emitter,
        private RunnerOptions $options = new RunnerOptions(),
        private Requirements $requirements = new Requirements(),
        private ?CoverageCollector $coverage = null,
        private SnapshotRepository $snapshots = new SnapshotRepository(),
        private DependencyValues $dependencies = new DependencyValues(),
    ) {
        $this->statics = new StaticRegistry();
        $this->issues  = new IssueCollector(
            $options->projectDirectories,
            $options->workingDirectory,
            $options->deprecationBaseline,
        );
    }

    /**
     * @param list<TestGroup> $groups
     * @param ?callable(): ?RunSummary $afterTests run-scoped work emitted inside the run (checks, and the Vitest fold-in returning its count delta)
     *                                      bracket — after the suite, before RunFinished, so their
     *                                      events precede the last event of the stream
     */
    public function run(array $groups, ?callable $afterTests = null): RunSummary
    {
        $started = hrtime(true);

        $planned = 0;

        foreach ($groups as $group) {
            $planned += count($group->tests);
        }

        $this->emitter->emit(new RunStarted(tests: $planned));

        $summary = $this->execute($groups);

        // Complete = nothing narrowed the plan (CLI knowledge, carried
        // in the options) and nothing halted it (stop-on leaves
        // planned tests unfinished) — the D-071 pruning gate. Computed on
        // the PHP plan BEFORE any external results (Vitest) fold in, so a
        // folded-in suite never touches completeness.
        $complete = $this->options->fullSuite && $summary->total() === $planned;

        if ($afterTests !== null) {
            $delta = $afterTests();

            if ($delta instanceof RunSummary) {
                $summary = $summary->plus($delta);
            }
        }

        $this->emitter->emit(new RunFinished(
            $summary,
            (hrtime(true) - $started) / 1e9,
            $complete,
        ));

        return $summary;
    }

    /**
     * Runs the groups without opening or closing a stream — run:start
     * and run:finish belong to whoever owns the whole run, which is
     * this method's caller when executions are composed (the process
     * pool mixing in-process and worker execution on one stream).
     *
     * @param list<TestGroup> $groups
     */
    public function execute(array $groups): RunSummary
    {
        $counts = ['pass' => 0, 'fail' => 0, 'error' => 0, 'skip' => 0, 'incomplete' => 0, 'risky' => 0, 'untested' => 0];

        $this->issues->resetTallies();

        foreach ($groups as $group) {
            if ($this->runGroup($group, $counts)) {
                break;
            }
        }

        $issues = $this->issues->tallies();

        return new RunSummary(
            passed: $counts['pass'],
            failed: $counts['fail'],
            errored: $counts['error'],
            skipped: $counts['skip'],
            incomplete: $counts['incomplete'],
            risky: $counts['risky'],
            deprecations: $issues['deprecations'],
            notices: $issues['notices'],
            warnings: $issues['warnings'],
            untested: $counts['untested'],
        );
    }

    /**
     * Returns true when a stop-on option asks the whole run to halt.
     * The group's after-all cleanup still runs before halting.
     *
     * @param array<string, int> $counts
     */
    private function runGroup(TestGroup $group, array &$counts): bool
    {
        try {
            if ($group->beforeAll instanceof Closure) {
                ($group->beforeAll)();
            }
        } catch (Throwable $throwable) {
            // A failing before-all errors every test in the group.
            foreach ($group->tests as $definition) {
                $this->emitter->emit(new TestStarted($definition->id));
                $this->emitter->emit(new TestFinished(
                    $definition->id,
                    Outcome::Errored,
                    0.0,
                    $this->failureFrom($throwable),
                ));
                $counts['error']++;
            }

            return $this->shouldStop(Outcome::Errored);
        }

        // Seeded, not empty: under process isolation a prerequisite ran
        // in an earlier worker, and its return value came back with it.
        /** @var array<string, array{passed: bool, value: mixed}> $results */
        $results = $this->dependencies->seed();

        $halt = false;

        foreach ($group->tests as $definition) {
            [$outcome, $blocked, $kinds] = $this->runTest($definition, $results);
            $counts[$outcome->value]++;

            if ($blocked) {
                $counts['untested']++;
            }

            if ($this->shouldStop($outcome, $kinds)) {
                $halt = true;

                break;
            }
        }

        try {
            if ($group->afterAll instanceof Closure) {
                ($group->afterAll)();
            }
        } catch (Throwable) {
            // After-all failures do not change test outcomes.
        }

        return $halt;
    }

    /**
     * The spec's stop-on semantics: failure and error each have their
     * own switch; defect covers both plus risky. The issue-triggered
     * switches read what the test emitted rather than how it ended — a
     * passing test that deprecates something still stops the run under
     * --stop-on-deprecation, which is the whole point of having it.
     *
     * @param list<IssueKind> $issues the issues this test triggered
     */
    private function shouldStop(Outcome $outcome, array $issues = []): bool
    {
        $byOutcome = match ($outcome) {
            Outcome::Errored    => $this->options->stopOnError || $this->options->stopOnDefect,
            Outcome::Failed     => $this->options->stopOnFailure || $this->options->stopOnDefect,
            Outcome::Risky      => $this->options->stopOnRisky || $this->options->stopOnDefect,
            Outcome::Skipped    => $this->options->stopOnSkipped,
            Outcome::Incomplete => $this->options->stopOnIncomplete,
            default             => false,
        };

        if ($byOutcome) {
            return true;
        }

        return array_any($issues, fn(IssueKind $kind): bool => match ($kind) {
            IssueKind::Deprecation => $this->options->stopOnDeprecation,
            IssueKind::Notice      => $this->options->stopOnNotice,
            IssueKind::Warning     => $this->options->stopOnWarning,
        });
    }

    /**
     * @param array<string, array{passed: bool, value: mixed}> $results per-group results, by test name
     *
     * @return array{0: Outcome, 1: bool, 2: list<IssueKind>} the terminal outcome, whether it went untested (a gated blocked skip), and the issues it triggered
     */
    private function runTest(TestDefinition $definition, array &$results): array
    {
        $this->emitter->emit(new TestStarted($definition->id));

        // A todo marker blocks execution before anything else is even
        // considered (D-045): the placeholder reports incomplete with
        // its label — requirements and dependencies of a body that will
        // not run are irrelevant. wip and done run their bodies (Pest
        // parity); a body-less wip/done is folded to a todo upstream.
        $todo = $definition->metadata->first(Todo::class);

        if ($todo instanceof Todo && $todo->blocksExecution()) {
            return $this->finish($definition, Outcome::Incomplete, 0.0, reason: $todo->label());
        }

        $unmet = $this->requirements->unmet($definition->metadata);

        if ($unmet !== []) {
            return $this->finish($definition, Outcome::Skipped, 0.0, reason: implode(' ', $unmet), blocked: true);
        }

        $dependencyValues = [];

        foreach ($definition->dependencies as $dependency) {
            if (!isset($results[$dependency]) || !$results[$dependency]['passed']) {
                return $this->finish($definition, Outcome::Skipped, 0.0, reason: sprintf(
                    'This test depends on "%s", which did not pass.',
                    $dependency,
                ), blocked: true);
            }

            $dependencyValues[] = $results[$dependency]['value'];
        }

        // Retry-with-classification (G4, nextest semantics): a fail or
        // error re-runs up to the retry budget; a pass on a retry is
        // FLAKY, never silently green — the attempt count travels on
        // the event, the reason names the earlier failure, and every
        // failed attempt's Failure rides along for the JUnit flaky
        // markup (D-043). Between attempts the policy's backoff pauses
        // (fixed/exponential/jittered).
        $policy  = $definition->metadata->first(Retry::class)?->policy() ?? $this->options->retries;
        $attempt = 0;
        $earlier = null;

        /** @var list<Failure> $retried */
        $retried = [];

        do {
            $attempt++;
            $run = $this->attempt($definition, $dependencyValues);

            if ($run['outcome'] !== Outcome::Failed && $run['outcome'] !== Outcome::Errored) {
                break;
            }

            $earlier = $run['failure'];

            if ($attempt <= $policy->count) {
                if ($earlier instanceof Failure) {
                    $retried[] = $earlier;
                }

                $delay = $policy->delayFor($attempt);

                if ($delay > 0.0) {
                    usleep((int) ($delay * 1_000_000));
                }
            }
        } while ($attempt <= $policy->count);

        $outcome = $run['outcome'];
        $reason  = $run['reason'];
        $name    = $definition->id->name;

        if ($outcome === Outcome::Passed) {
            // Every dataset row must pass for the method to count as a
            // passed dependency; the last row's value is what
            // dependents receive.
            $results[$name] = [
                'passed' => !isset($results[$name]) || $results[$name]['passed'],
                'value'  => $run['value'],
            ];

            $this->dependencies->capture($name, $run['value']);

            if ($attempt > 1) {
                $reason = sprintf(
                    'Flaky: passed on attempt %d of %d after: %s',
                    $attempt,
                    $policy->count + 1,
                    $earlier instanceof Failure ? $earlier->message : 'an earlier failure',
                );
            }
        } elseif ($run['thrown']) {
            $results[$name] = ['passed' => false, 'value' => null];
        }

        return $this->finish(
            $definition,
            $outcome,
            $run['duration'],
            $run['failure'],
            $attempt,
            $reason,
            $run['issues'],
            $run['property'],
            $retried,
            $run['snapshots'],
            // Clean only counts on a first-attempt pass (D-071): a
            // flaky pass must not prune a sequence that still catches
            // the bug some of the time.
            $outcome === Outcome::Passed && $attempt === 1 ? $run['propertyClean'] : [],
            $run['snapshotKeys'],
        );
    }

    /**
     * One full execution of the test — lifecycle, classification,
     * state settlement — with no event emitted: runTest owns the
     * one test:finish, whatever the attempt count was.
     *
     * @param list<mixed> $dependencyValues
     *
     * @return array{outcome: Outcome, duration: float, failure: ?Failure, reason: ?string, issues: list<\LucianoPereira\Crucible\Event\Issue>, value: mixed, thrown: bool, property: ?array{key: non-empty-string, choices: list<int>}, snapshots: ?array{created: int, updated: int}, propertyClean: list<non-empty-string>, snapshotKeys: list<non-empty-string>}
     */
    private function attempt(TestDefinition $definition, array $dependencyValues): array
    {
        // Per-test assertion accounting: the runner owns the counter's
        // window; user code may reset it inside a test without
        // corrupting risky detection.
        Assert::resetAssertionCount();

        $snapshot = $this->snapshotFor($definition->metadata);

        $this->issues->install();

        // The property bridge (D-040): opened per attempt so property
        // keys count identically on retries. The snapshot context
        // (D-042) follows the same rule — snapshot counters restart
        // per attempt.
        PropertyContext::begin($definition->id, $this->options->propertyFailures);
        Snapshots::begin($definition->id, $this->options->updateSnapshots, $this->snapshots, $this->options->workingDirectory, $this->options->inlineSnapshotRewrite);
        Browsing::begin();
        MockeryContainer::reset();

        // Per-test coverage window (D-041): the definition closure is
        // the whole lifecycle, so hooks are attributed to their test.
        $this->coverage?->begin();

        $started = hrtime(true);
        $value   = null;
        $thrown  = null;
        $window  = null;
        $output  = '';

        // What the test prints is the test's, not the runner's: buffered
        // so it can be reported as an event and policed by
        // --disallow-test-output, then written back out so capturing it
        // never means losing it.
        ob_start();

        try {
            $value = ($definition->test)($dependencyValues);
        } catch (Throwable $throwable) {
            $thrown = $throwable;
        } finally {
            $buffered = ob_get_clean();
            $output   = $buffered === false ? '' : $buffered;

            PropertyContext::end();
            Snapshots::end();
            // Recording waits: what this window may contribute
            // depends on the outcome and the covers claim (D-063).
            $window = $this->coverage?->end();
        }

        if ($output !== '') {
            $this->emitter->emit(new TestOutputWritten($definition->id, OutputChannel::Stdout, $output));

            if (!$this->options->disallowTestOutput) {
                print $output;
            }
        }

        $duration  = $this->since($started);
        $issues    = $this->issues->drain();
        $pollution = $this->settleGlobalState($definition->metadata, $snapshot);

        // Browser failure artifact: captured before the test's
        // contexts close, only when the test actually visited a page
        // and genuinely failed (skips and incompletes are not
        // failures). The screenshot path joins the failure message —
        // the incumbent's exact courtesy.
        $browserScreenshot = null;

        if ($thrown instanceof Throwable
            && !$thrown instanceof SkippedTestError
            && !$thrown instanceof IncompleteTestError
            && !RealPhpUnitOutcomes::isSkipped($thrown)
            && !RealPhpUnitOutcomes::isIncomplete($thrown)) {
            $browserScreenshot = Browsing::failureScreenshot($definition->id);
        }

        Browsing::end();

        $result = [
            'outcome'  => Outcome::Passed,
            'duration' => $duration,
            'failure'  => null,
            'reason'   => null,
            'issues'   => $issues,
            'value'    => $value,
            'thrown'   => $thrown instanceof Throwable,
            'property' => $thrown instanceof PropertyFailedError
                ? ['key' => $thrown->key, 'choices' => $thrown->choices]
                : null,
            'snapshots'     => Snapshots::recorded(),
            'propertyClean' => PropertyContext::replayedClean(),
            'snapshotKeys'  => Snapshots::visited(),
        ];

        if ($thrown instanceof Throwable) {
            $result = match (true) {
                $thrown instanceof SkippedTestError || RealPhpUnitOutcomes::isSkipped($thrown) => [...$result, 'outcome' => Outcome::Skipped, 'reason' => $thrown->getMessage()],
                // Incomplete carries its trace where skipped does not:
                // "not finished yet" is a note about a place in the
                // code, and the reader wants to jump to it. A skip is a
                // statement about the environment, and points nowhere.
                $thrown instanceof IncompleteTestError || RealPhpUnitOutcomes::isIncomplete($thrown) => [...$result, 'outcome' => Outcome::Incomplete, 'reason' => $thrown->getMessage(), 'failure' => $this->failureFrom($thrown, $browserScreenshot)],
                $thrown instanceof AssertionFailedError || RealPhpUnitOutcomes::isFailure($thrown)   => [...$result, 'outcome' => Outcome::Failed, 'failure' => $this->failureFrom($thrown, $browserScreenshot)],
                default                                                                              => [...$result, 'outcome' => Outcome::Errored, 'failure' => $this->failureFrom($thrown, $browserScreenshot)],
            };

            return $this->settleCoverage($definition, $result, $window);
        }

        if ($pollution !== []) {
            return $this->settleCoverage($definition, [...$result, 'outcome' => Outcome::Risky, 'reason' => 'This test modified global state: ' . implode(', ', $pollution) . '.'], $window);
        }

        // A test slower than its declared size allows (D-101). Judged on
        // the duration already measured, so the budget covers exactly
        // what ran and the reason states the measurement.
        $limit = $this->options->enforceTimeLimit
            ? TimeLimit::forTest($definition->metadata, $this->options->defaultTimeLimit)
            : null;

        // --disallow-test-output: a test that prints is a test whose
        // result depends on something the assertions do not describe.
        if ($this->options->disallowTestOutput && $output !== '') {
            return $this->settleCoverage($definition, [
                ...$result,
                'outcome' => Outcome::Risky,
                'reason'  => 'This test printed unexpected output: ' . trim($output),
            ], $window);
        }

        if ($limit !== null && $duration > $limit) {
            // The budget is judged on a measured duration, so a verdict
            // reached under a call-hooking xdebug says so rather than
            // blaming the test for the profiler.
            $notice = TimingOverhead::notice();

            return $this->settleCoverage($definition, [
                ...$result,
                'outcome' => Outcome::Risky,
                'reason'  => sprintf('This test took %.3f seconds, longer than the %d second(s) its size allows.', $duration, $limit)
                    . ($notice === null ? '' : ' ' . $notice),
            ], $window);
        }

        $performedAssertions = Assert::assertionCount() > 0;

        // --do-not-report-useless-tests turns the whole classification off,
        // for a suite whose tests assert through something Crucible cannot
        // see. #[DoesNotPerformAssertions] stays the per-test way to say it.
        if ($this->options->reportUselessTests && !$performedAssertions && !$definition->metadata->has(DoesNotPerformAssertions::class)) {
            return $this->settleCoverage($definition, [...$result, 'outcome' => Outcome::Risky, 'reason' => 'This test did not perform any assertions.'], $window);
        }

        return $this->settleCoverage($definition, $result, $window);
    }

    /**
     * The coverage-metadata settlement (D-063), oracle-pinned:
     * requireCoverageMetadata reclassifies a passing test without a
     * covers claim as risky (with or without a coverage run);
     * beStrictAboutCoverageMetadata reclassifies a passing test that
     * executed source code outside its covered/used targets, naming
     * the stray units. Then the window records — the per-test map
     * keeps observed truth, the aggregate takes the covers-filtered
     * contribution, and a risky test's hits are demoted (the oracle
     * discards the coverage but keeps the denominators).
     *
     * @param array{outcome: Outcome, duration: float, failure: ?Failure, reason: ?string, issues: list<\LucianoPereira\Crucible\Event\Issue>, value: mixed, thrown: bool, property: ?array{key: non-empty-string, choices: list<int>}, snapshots: ?array{created: int, updated: int}, propertyClean: list<non-empty-string>, snapshotKeys: list<non-empty-string>} $result
     *
     * @return array{outcome: Outcome, duration: float, failure: ?Failure, reason: ?string, issues: list<\LucianoPereira\Crucible\Event\Issue>, value: mixed, thrown: bool, property: ?array{key: non-empty-string, choices: list<int>}, snapshots: ?array{created: int, updated: int}, propertyClean: list<non-empty-string>, snapshotKeys: list<non-empty-string>}
     */
    private function settleCoverage(TestDefinition $definition, array $result, ?CoverageWindow $window): array
    {
        $enforcing = $this->options->requireCoverageMetadata || $this->options->beStrictAboutCoverageMetadata;

        if (!$enforcing && !$window instanceof CoverageWindow) {
            return $result;
        }

        $targets = CoversTargets::from($definition->metadata);

        if ($result['outcome'] === Outcome::Passed
            && $this->options->requireCoverageMetadata
            && !$targets->declared) {
            $result = [...$result, 'outcome' => Outcome::Risky, 'reason' => 'This test does not define a code coverage target but is expected to do so'];
        }

        if ($result['outcome'] === Outcome::Passed
            && $this->options->beStrictAboutCoverageMetadata
            && $window instanceof CoverageWindow) {
            $strays = $targets->strays($window);

            if ($strays !== []) {
                $result = [...$result, 'outcome' => Outcome::Risky, 'reason' => "This test executed code that is not listed as code to be covered or used:\n- " . implode("\n- ", $strays)];
            }
        }

        if ($window instanceof CoverageWindow) {
            // --disable-coverage-targeting: the aggregate takes everything
            // the test executed, rather than only what its Covers* claims
            // let it contribute.
            $contribution = $this->options->coverageTargeting ? $targets->contribution($window) : $window;

            // A test that did not finish did not cover what it
            // touched. A skip runs markTestSkipped() and stops; an
            // error threw something nobody expected partway through.
            // Those lines executed in the literal sense and say nothing
            // about the code under test — the incumbent lists them as
            // executable with no hits, which is what withoutHits()
            // already said for risky. A *failure* is different and
            // still counts: the test ran to its assertion and disagreed
            // with it, which is a complete measurement.
            $settled = in_array($result['outcome'], [Outcome::Risky, Outcome::Skipped, Outcome::Incomplete, Outcome::Errored], true);

            $this->coverage?->record(
                $definition->id,
                $window,
                $settled ? $window->withoutHits() : $contribution,
            );
        }

        return $result;
    }

    /**
     * Takes the pre-test snapshot when backup or strict-global-state
     * checking asks for one. Method metadata overrides configuration
     * (method-before-class precedence is already in the collection).
     */
    private function snapshotFor(MetadataCollection $metadata): ?GlobalStateSnapshot
    {
        $backupGlobals = $metadata->first(BackupGlobals::class)->enabled ?? $this->options->backupGlobals;
        $backupStatics = $metadata->first(BackupStaticProperties::class)->enabled ?? $this->options->backupStaticProperties;

        if (!$backupGlobals && !$backupStatics && !$this->options->beStrictAboutChangesToGlobalState) {
            return null;
        }

        $excludedGlobals = [];

        foreach ($metadata->ofType(ExcludeGlobalVariableFromBackup::class) as $exclusion) {
            $excludedGlobals[] = $exclusion->globalVariableName;
        }

        $excludedStatics = [];

        foreach ($metadata->ofType(ExcludeStaticPropertyFromBackup::class) as $exclusion) {
            $excludedStatics[] = [$exclusion->className, $exclusion->propertyName];
        }

        return new GlobalStateSnapshot(
            $this->statics,
            $excludedGlobals,
            $excludedStatics,
            includeStatics: $backupStatics || $this->options->beStrictAboutChangesToGlobalState,
        );
    }

    /**
     * Detects pollution (strict mode) and restores backed-up state.
     * Returns the change list when the run is strict, empty otherwise.
     *
     * @return list<non-empty-string>
     */
    private function settleGlobalState(MetadataCollection $metadata, ?GlobalStateSnapshot $snapshot): array
    {
        if (!$snapshot instanceof \LucianoPereira\Crucible\Isolation\GlobalStateSnapshot) {
            return [];
        }

        $changes = $this->options->beStrictAboutChangesToGlobalState ? $snapshot->changes() : [];

        $backupGlobals = $metadata->first(BackupGlobals::class)->enabled ?? $this->options->backupGlobals;
        $backupStatics = $metadata->first(BackupStaticProperties::class)->enabled ?? $this->options->backupStaticProperties;

        if ($backupGlobals || $backupStatics) {
            $snapshot->restore();
        }

        return $changes;
    }

    /**
     * @param positive-int                                      $attempt
     * @param list<\LucianoPereira\Crucible\Event\Issue>           $issues
     * @param ?array{key: non-empty-string, choices: list<int>} $property
     * @param list<Failure>                                     $retried
     * @param ?array{created: int, updated: int}                $snapshots
     * @param list<non-empty-string>                            $propertyClean
     * @param list<non-empty-string>                            $snapshotKeys
     *
     * @return array{0: Outcome, 1: bool, 2: list<IssueKind>} the terminal outcome, whether it went untested (a gated blocked skip), and the kinds of issue it triggered — what the stop-on-issue switches read
     */
    private function finish(TestDefinition $definition, Outcome $outcome, float $duration, ?Failure $failure = null, int $attempt = 1, ?string $reason = null, array $issues = [], ?array $property = null, array $retried = [], ?array $snapshots = null, array $propertyClean = [], array $snapshotKeys = [], bool $blocked = false): array
    {
        // Expected-outcome reconciliation (D-073): a test that declared
        // how it expects to end is judged against the outcome computed
        // above. This is the single choke point every terminal outcome
        // passes through — a declared ->skip(), a body markTestSkipped,
        // an unmet requirement, and a todo block alike — so the
        // inversion needs no cooperation from any of the callers.
        $expected = $definition->metadata->first(ExpectedOutcome::class);

        if ($expected instanceof ExpectedOutcome) {
            [$outcome, $failure, $reason] = $this->reconcileExpectedOutcome($expected, $outcome, $reason);
        }

        // The blocked flag (D-075) only rides a skip that stayed a skip:
        // an ->expectsSkip() that reconciled a requirement-skip to Passed
        // asserted its way out of "untested" and must not carry it.
        $blocked = $blocked && $outcome === Outcome::Skipped;

        // Read and clear here rather than at the measurement: finish()
        // is the one path every outcome takes, including the skips that
        // never reach attempt() and so never reset it themselves. A
        // test that did not run reports 0 because the previous test's
        // finish() left the counter there.
        $assertions = Assert::assertionCount();
        Assert::resetAssertionCount();

        $this->emitter->emit(new TestFinished(
            $definition->id,
            $outcome,
            $duration,
            $failure,
            $attempt,
            $reason !== null && $reason !== '' ? $reason : null,
            $issues,
            $this->quarantined($definition),
            $property,
            $retried,
            $snapshots,
            $propertyClean,
            $snapshotKeys,
            $blocked,
            $assertions,
        ));

        return [$outcome, $blocked, array_map(static fn(Issue $issue): IssueKind => $issue->kind, $issues)];
    }

    /**
     * Reconcile the actual terminal outcome against a declared
     * expectation (D-073): a match is the pass, a mismatch the failure.
     * When a reason fragment was declared it must appear in the actual
     * reason (the ->fails('fragment') symmetry). The returned outcome
     * is what finish() emits and returns, so stop-on-failure and the
     * exit code see the reconciled result, not the raw one.
     *
     * @return array{0: Outcome, 1: ?Failure, 2: ?string}
     */
    private function reconcileExpectedOutcome(ExpectedOutcome $expected, Outcome $actual, ?string $reason): array
    {
        if ($actual !== $expected->expected) {
            return [
                Outcome::Failed,
                new Failure(sprintf(
                    'Expected the test to %s, but it %s.',
                    $expected->verb(),
                    $this->describeOutcome($actual),
                )),
                null,
            ];
        }

        if ($expected->reasonFragment !== null && !str_contains((string) $reason, $expected->reasonFragment)) {
            return [
                Outcome::Failed,
                new Failure(sprintf(
                    'Expected the %s reason to contain "%s", but it was "%s".',
                    $expected->verb(),
                    $expected->reasonFragment,
                    (string) $reason,
                )),
                null,
            ];
        }

        return [
            Outcome::Passed,
            null,
            sprintf('Expected outcome met: %s.', $actual->value),
        ];
    }

    /**
     * The actual outcome read as a past-tense clause, for the
     * "but it <clause>" half of a mismatch message.
     *
     * @return non-empty-string
     */
    private function describeOutcome(Outcome $outcome): string
    {
        return match ($outcome) {
            Outcome::Passed     => 'passed',
            Outcome::Failed     => 'failed',
            Outcome::Errored    => 'errored',
            Outcome::Skipped    => 'was skipped',
            Outcome::Incomplete => 'was incomplete',
            Outcome::Risky      => 'was risky',
        };
    }

    /**
     * Whether the test is quarantined (G4): marked #[Quarantined] in
     * code, or listed in the configuration by id — the full
     * `file::name#dataset` string, or `file::name` covering every row.
     */
    private function quarantined(TestDefinition $definition): bool
    {
        if ($definition->metadata->has(Quarantined::class)) {
            return true;
        }

        if ($this->options->quarantine === []) {
            return false;
        }

        $id = $definition->id;

        return in_array($id->toString(), $this->options->quarantine, true)
            || in_array($id->file . '::' . $id->name, $this->options->quarantine, true);
    }

    private function since(int $startedNanoseconds): float
    {
        return (hrtime(true) - $startedNanoseconds) / 1e9;
    }

    private function failureFrom(Throwable $throwable, ?string $browserScreenshot = null): Failure
    {
        $frames = [];

        // The throw site first, because getTrace() does not contain it:
        // a trace is the call stack, and the line that threw is only on
        // the throwable itself. Without it a `throw` written directly in
        // a test body reports the frame that called the test — engine
        // code — and the formats that need a real file and line (SARIF,
        // JSON) point somewhere the author never wrote.
        if ($throwable->getFile() !== '' && $throwable->getLine() > 0) {
            $frames[] = new Frame($throwable->getFile(), $throwable->getLine());
        }

        foreach ($throwable->getTrace() as $frame) {
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;

            if ($file === '' || $line < 1) {
                continue;
            }

            $frames[] = new Frame(
                $file,
                $line,
                $frame['function'] !== '' ? $frame['function'] : null,
            );
        }

        $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : $throwable::class;

        if ($browserScreenshot !== null) {
            $message .= sprintf("\nA screenshot of the page has been saved to [%s].", $browserScreenshot);
        }

        $comparison = $throwable instanceof AssertionFailedError ? $throwable->comparison : null;
        $expected   = $comparison?->expected;
        $actual     = $comparison?->actual;

        return new Failure(
            $message,
            $throwable::class,
            $frames,
            $expected === '' ? null : $expected,
            $actual === '' ? null : $actual,
        );
    }
}
