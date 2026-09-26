<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner\Process;

use LucianoPereira\Crucible\Attributes\PreserveGlobalState;
use LucianoPereira\Crucible\Attributes\RunClassInSeparateProcess;
use LucianoPereira\Crucible\Attributes\RunInSeparateProcess;
use LucianoPereira\Crucible\Attributes\RunTestsInSeparateProcesses;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Configuration\Overrides;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\IssueKind;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunFinished;
use LucianoPereira\Crucible\Event\RunStarted;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Isolation\GlobalStateExport;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Runner\TimingOverhead;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;
use LucianoPereira\Crucible\Test\TestId;

use function array_map;
use function array_shift;
use function array_values;
use function bin2hex;
use function count;
use function extension_loaded;
use function file_get_contents;
use function fwrite;
use function hrtime;
use function in_array;
use function is_array;
use function is_file;
use function json_decode;
use function max;
use function random_bytes;
use function range;
use function spl_object_id;
use function sprintf;
use function str_contains;
use function stream_select;
use function sys_get_temp_dir;
use function trim;
use function unlink;
use function usort;

use const PHP_EOL;
use const STDERR;

/**
 * The supervisor of the worker pool (nextest architecture): plans
 * which tests run in-process and which run in spawned workers, feeds
 * workers manifests, multiplexes their NDJSON streams, and re-emits
 * every test-scoped event onto the one supervisor-owned stream —
 * listeners (console, NDJSON log, result cache) cannot tell where a
 * test ran.
 *
 * Workers are consumed from a queue in LPT order (Graham 1969):
 * costliest unit first, next unit to the first free slot — cached
 * durations make the estimate. A worker that reaches EOF without its
 * run:finish handshake crashed: every expected-but-unfinished test is
 * reported as errored, with the worker's stderr tail as the reason.
 * The crash is contained; the run continues.
 */
final class Supervisor
{
    private const float SELECT_TIMEOUT_SECONDS = 0.05;

    /** @var array<string, int> */
    private array $counts = [
        'pass' => 0, 'fail' => 0, 'error' => 0, 'skip' => 0, 'incomplete' => 0, 'risky' => 0, 'untested' => 0,
    ];

    /** @var array<string, int> */
    private array $issueCounts = ['deprecations' => 0, 'notices' => 0, 'warnings' => 0];

    /**
     * @param non-empty-string  $crucibleBinary
     * @param non-empty-string  $configurationPath
     * @param ?int<0, max>      $retries           the run's resolved retry budget (G4), carried into every manifest
     * @param ?non-empty-string $browserOverride   CLI --browser engine, carried into every manifest (D-064)
     * @param Overrides         $overrides         configuration values the CLI overrode, carried into every manifest
     * @param list<non-empty-string> $includePaths the spec's --include-path, carried into every manifest
     * @param bool              $fullSuite         no CLI selection narrowed the plan (D-071); with every planned test finished, run:finish reports complete
     * @param bool              $processIsolation  the spec's --process-isolation: every test gets its own worker, as if it carried #[RunInSeparateProcess]
     * @param DependencyValues  $dependencyValues  shared with the in-process runner, so a prerequisite that ran here reaches a dependent that runs in a worker
     */
    public function __construct(
        private readonly Emitter $emitter,
        private readonly TestRunner $inProcessRunner,
        private readonly ResultCache $history,
        private readonly EventParser $parser,
        private readonly string $crucibleBinary,
        private readonly string $configurationPath,
        private readonly WorkingDirectory $workingDirectory,
        private readonly ExecutionOrder $order = ExecutionOrder::Declared,
        private readonly int $seed = 0,
        private readonly int $parallel = 1,
        private readonly ?int $retries = null,
        private readonly ?string $coverageDirectory = null,
        private readonly bool $updateSnapshots = false,
        private readonly bool $coverageBranch = false,
        private readonly bool $pathCoverage = false,
        private readonly ?bool $strictCoverage = null,
        private readonly ?string $browserOverride = null,
        private readonly bool $debugOverride = false,
        private readonly bool $fullSuite = false,
        private readonly Overrides $overrides = new Overrides(),
        private readonly array $includePaths = [],
        private readonly bool $processIsolation = false,
        private readonly DependencyValues $dependencyValues = new DependencyValues(),
    ) {}

    /**
     * Workers do not inherit the parent's -d flags, so whichever
     * driver the parent is running with is re-created on the child
     * command line (D-041). A driver the child's php.ini already
     * loads produces a harmless already-loaded notice.
     *
     * Without coverage the child gets the opposite instruction: an
     * xdebug hooking every call inflates every duration the worker
     * reports, and the durations feed the result cache the scheduler
     * plans from. Step debugging is left alone — a mode carrying
     * `debug` is an operator with a debugger attached, not a default.
     *
     * @return list<non-empty-string>
     */
    private function phpArguments(): array
    {
        if ($this->coverageDirectory === null) {
            return extension_loaded('xdebug') && !str_contains(TimingOverhead::mode(), 'debug')
                ? ['-d', 'xdebug.mode=off']
                : [];
        }

        // Branch analysis is xdebug territory (D-062) — a pcov child
        // could collect lines but never branches, so the supervisor
        // recreates the driver its own detection chose.
        if (extension_loaded('pcov') && !$this->coverageBranch) {
            return ['-d', 'extension=pcov.so', '-d', 'pcov.enabled=1'];
        }

        if (extension_loaded('xdebug')) {
            return ['-d', 'zend_extension=xdebug.so', '-d', 'xdebug.mode=coverage'];
        }

        return [];
    }

    /**
     * @param list<TestGroup>  $groups     already scheduled
     * @param ?callable(): ?RunSummary $afterTests run-scoped work emitted inside the run (checks, and the Vitest fold-in returning its count delta)
     *                                      bracket — after the workers, before RunFinished
     */
    public function run(array $groups, ?callable $afterTests = null): RunSummary
    {
        $started = hrtime(true);

        $planned = 0;

        foreach ($groups as $group) {
            $planned += count($group->tests);
        }

        // Same plan size the sequential runner announces: a reader
        // showing progress against a total must not lose it because the
        // run happened to be parallel.
        $this->emitter->emit(new RunStarted(tests: $planned));

        [$inProcess, $units] = $this->plan($groups);

        // #[Depends] across the process boundary: a unit that needs a
        // value some other unit produces makes that producer record it.
        // Asked for before anything runs, so the in-process portion —
        // which goes first — keeps what the workers will need.
        $needed = [];

        foreach ($units as $unit) {
            foreach ($unit->dependsOn as $name) {
                if (!in_array($name, $needed, true)) {
                    $needed[] = $name;
                }
            }
        }

        $this->dependencyValues->want($needed);

        $units = array_map(
            static function (PlannedUnit $planned) use ($needed): PlannedUnit {
                $provides = [];

                foreach ($planned->expected as $id) {
                    if (in_array($id->name, $needed, true) && !in_array($id->name, $provides, true)) {
                        $provides[] = $id->name;
                    }
                }

                return $planned->providing($provides);
            },
            $units,
        );

        if ($inProcess !== []) {
            $this->tallySummary($this->inProcessRunner->execute($inProcess));
        }

        $this->runUnits($units);

        $delta = $afterTests !== null ? $afterTests() : null;

        $summary = new RunSummary(
            passed: $this->counts['pass'],
            failed: $this->counts['fail'],
            errored: $this->counts['error'],
            skipped: $this->counts['skip'],
            incomplete: $this->counts['incomplete'],
            risky: $this->counts['risky'],
            deprecations: $this->issueCounts['deprecations'],
            notices: $this->issueCounts['notices'],
            warnings: $this->issueCounts['warnings'],
            untested: $this->counts['untested'],
        );

        // Same D-071 completeness rule as the sequential runner: lost
        // workers report their tests as errored, so the tally only falls
        // short of the plan when something halted the run. Computed on
        // the PHP plan before external results (Vitest) fold in.
        $complete = $this->fullSuite && $summary->total() === $planned;

        if ($delta instanceof RunSummary) {
            $summary = $summary->plus($delta);
        }

        $this->emitter->emit(new RunFinished(
            $summary,
            (hrtime(true) - $started) / 1e9,
            $complete,
        ));

        return $summary;
    }

    /**
     * Splits the scheduled groups into the in-process remainder and
     * the worker units, honoring the spec's three isolation levels:
     * #[RunClassInSeparateProcess] sends the whole group to one
     * worker; #[RunTestsInSeparateProcesses] (class) and
     * #[RunInSeparateProcess] (method) send each marked test to its
     * own worker. --process-isolation is the run-wide form of the
     * last one: every test is marked. With --parallel above 1 there
     * is no in-process remainder: every group becomes a unit.
     *
     * @param list<TestGroup> $groups
     *
     * @return array{list<TestGroup>, list<PlannedUnit>}
     */
    private function plan(array $groups): array
    {
        $inProcess = [];
        $units     = [];

        foreach ($groups as $group) {
            if ($group->tests === []) {
                continue;
            }

            $file = $group->tests[0]->id->file;

            $wholeClass = false;

            foreach ($group->tests as $test) {
                if ($test->metadata->has(RunClassInSeparateProcess::class)) {
                    $wholeClass = true;

                    break;
                }
            }

            if ($wholeClass) {
                $units[] = $this->unitFor($file, $group->tests, isolated: true);

                continue;
            }

            $remaining     = [];
            $isolatedNames = [];

            foreach ($group->tests as $test) {
                $isolated = $this->processIsolation
                    || $test->metadata->has(RunInSeparateProcess::class)
                    || $test->metadata->has(RunTestsInSeparateProcesses::class);

                if (!$isolated) {
                    $remaining[] = $test;

                    continue;
                }

                // Dataset rows share a declared name and one worker.
                if (!in_array($test->id->name, $isolatedNames, true)) {
                    $isolatedNames[] = $test->id->name;
                }
            }

            foreach ($isolatedNames as $name) {
                $rows = [];

                foreach ($group->tests as $test) {
                    if ($test->id->name === $name) {
                        $rows[] = $test;
                    }
                }

                $units[] = $this->unitFor($file, $rows, isolated: true);
            }

            if ($remaining === []) {
                continue;
            }

            if ($this->parallel > 1) {
                $units[] = $this->unitFor($file, $remaining);

                continue;
            }

            $inProcess[] = count($remaining) === count($group->tests)
                ? $group
                : new TestGroup($group->name, $remaining, $group->beforeAll, $group->afterAll);
        }

        return [$inProcess, $units];
    }

    /**
     * Units always carry exact id strings, never "the whole file" —
     * whatever selection (--filter, --group) removed on the
     * supervisor side must stay removed on the worker side.
     *
     * @param non-empty-string     $file
     * @param list<TestDefinition> $tests
     * @param bool                 $isolated the tests asked for their own process (see {@see attributeStderr()})
     */
    private function unitFor(string $file, array $tests, bool $isolated = false): PlannedUnit
    {
        $cost     = 0.0;
        $ids      = [];
        $expected = [];
        $own      = [];

        foreach ($tests as $test) {
            $expected[] = $test->id;
            $ids[]      = $test->id->toString();
            $own[]      = $test->id->name;
            $cost += $this->history->duration($test->id->toString()) ?? 0.0;
        }

        // A prerequisite this unit runs itself resolves in-process; only
        // what it cannot produce has to arrive from an earlier unit.
        $dependsOn = [];

        foreach ($tests as $test) {
            foreach ($test->dependencies as $dependency) {
                if (!in_array($dependency, $own, true) && !in_array($dependency, $dependsOn, true)) {
                    $dependsOn[] = $dependency;
                }
            }
        }

        // Method-before-class precedence is already settled in the
        // collection, so the first match is the effective one.
        $preserve = false;

        foreach ($tests as $test) {
            if ($test->metadata->first(PreserveGlobalState::class)?->enabled === true) {
                $preserve = true;

                break;
            }
        }

        return new PlannedUnit(new WorkUnit($file, $ids, $cost), $expected, $dependsOn, [], $preserve, $isolated);
    }

    /**
     * @param list<PlannedUnit> $units
     */
    private function runUnits(array $units): void
    {
        // LPT: costliest unit first, next unit to the first free slot.
        // The sort is stable, so unknown costs keep scheduled order.
        usort($units, static fn(PlannedUnit $a, PlannedUnit $b): int => $b->unit->estimatedCost <=> $a->unit->estimatedCost);

        /** @var array<int, WorkerProcess> $active */
        $active = [];

        /** @var array<int, array<string, true>> $finished finished test ids per worker */
        $finished = [];

        $slots = max($this->parallel, 1);

        // ParaTest's parallel contract: each worker slot carries a
        // stable TEST_TOKEN (1..N, reused as slots free up, so
        // token-keyed databases and caches are reused, not
        // multiplied) plus a run-unique UNIQUE_TEST_TOKEN. Only a
        // parallel run sets them.
        /** @var list<int<1, max>> $freeSlots */
        $freeSlots = range(1, $slots);

        /** @var array<int, int<1, max>> $slotOf worker object id => slot */
        $slotOf = [];

        $runToken = bin2hex(random_bytes(8));

        // What earlier units (and the in-process portion) produced.
        $available = $this->dependencyValues->provided();

        $exported = null;

        /** @var array<int, ?non-empty-string> $artifactOf worker object id => where it writes its values */
        $artifactOf = [];

        /** @var array<int, bool> $isolatedOf worker object id => its unit asked for its own process */
        $isolatedOf = [];

        while ($units !== [] || $active !== []) {
            while ($units !== [] && count($active) < $slots) {
                // A unit whose prerequisites have not been produced yet
                // waits for them, so long as something is still running
                // that might produce them. When nothing is active the
                // head goes anyway: an unsatisfiable dependency must
                // report untested, not stall the run.
                $index = $this->nextReady($units, $available, $active === []);

                if ($index === null) {
                    break;
                }

                $planned = $units[$index];
                unset($units[$index]);
                $units = array_values($units);
                $slot  = array_shift($freeSlots) ?? 1;

                $artifact = $planned->provides === []
                    ? null
                    : sys_get_temp_dir() . '/crucible-depends-' . bin2hex(random_bytes(6)) . '.json';

                $incoming = [];

                foreach ($planned->dependsOn as $name) {
                    if (isset($available[$name])) {
                        $incoming[$name] = $available[$name];
                    }
                }

                // #[PreserveGlobalState(true)]: captured at dispatch, so
                // the worker sees the state as it stands now rather than
                // as it stood when the run was planned. Captured once —
                // every later unit that asks gets the same snapshot,
                // which is also what makes the skip warnings print once.
                $globalState = null;

                if ($planned->preserveGlobalState) {
                    if (!$exported instanceof GlobalStateExport) {
                        $exported = GlobalStateExport::capture();

                        // On stderr: stdout is the progress view's, and a
                        // note about the run is not a test event — it has
                        // no place in the NDJSON contract.
                        foreach ($exported->skipped as $name) {
                            fwrite(STDERR, sprintf(
                                'Global state was not preserved for %s: no serializer can carry it.' . PHP_EOL,
                                $name,
                            ));
                        }
                    }

                    $globalState = $exported->toJson();
                }

                $worker = WorkerProcess::spawn(
                    $this->crucibleBinary,
                    $this->workingDirectory,
                    new WorkerManifest(
                        $this->configurationPath,
                        [$planned->unit],
                        $this->order,
                        $this->seed,
                        $this->parallel > 1 ? $slot : null,
                        $this->parallel > 1 ? $runToken . '_' . $slot : null,
                        $this->retries,
                        $this->coverageDirectory !== null
                            ? $this->coverageDirectory . '/worker-' . bin2hex(random_bytes(6)) . '.json'
                            : null,
                        $this->updateSnapshots,
                        $this->coverageBranch,
                        $this->strictCoverage,
                        $this->browserOverride,
                        $this->debugOverride,
                        $this->overrides,
                        $this->includePaths,
                        $incoming,
                        $planned->provides,
                        $artifact,
                        $this->pathCoverage,
                        $globalState,
                    ),
                    $planned->expected,
                    $this->phpArguments(),
                );

                if (!$worker instanceof WorkerProcess) {
                    $freeSlots[] = $slot;
                    $this->reportLost($planned->expected, [], 'The worker process for this test could not be started.');

                    continue;
                }

                $active[spl_object_id($worker)]     = $worker;
                $finished[spl_object_id($worker)]   = [];
                $slotOf[spl_object_id($worker)]     = $slot;
                $artifactOf[spl_object_id($worker)] = $artifact;
                $isolatedOf[spl_object_id($worker)] = $planned->isolated;
            }

            if ($active === []) {
                continue;
            }

            $this->await($active);

            foreach ($active as $key => $worker) {
                foreach ($worker->readLines() as $line) {
                    if ($this->parser->isRunFinished($line)) {
                        $worker->markFinished();

                        continue;
                    }

                    $event = $this->parser->parse($line);

                    if (!$event instanceof \LucianoPereira\Crucible\Event\Event) {
                        continue;
                    }

                    // What the worker wrote before a test started (a
                    // startup notice, the bootstrap) belongs to no test;
                    // what it wrote while one ran belongs to that test.
                    if ($event instanceof TestStarted) {
                        $this->forwardStderr($worker->takeStderr());
                    }

                    if ($event instanceof TestFinished && $isolatedOf[$key]) {
                        $event = $this->attributeStderr($event, $worker->takeStderr());
                    } elseif ($event instanceof TestFinished) {
                        $this->forwardStderr($worker->takeStderr());
                    }

                    $this->emitter->emit($event);

                    if ($event instanceof TestFinished) {
                        $finished[$key][$event->test->toString()] = true;
                        $this->tally($event->outcome);

                        if ($event->blocked) {
                            $this->counts['untested']++;
                        }

                        $this->tallyIssues($event);
                    }
                }

                $worker->drainStderr();

                if (!$worker->atEof()) {
                    continue;
                }

                $worker->close();

                // A crash carries the tail in its reason already; a clean
                // exit hands over whatever came after the last test.
                if ($worker->hasFinished()) {
                    $this->forwardStderr($worker->takeStderr());
                }

                if (!$worker->hasFinished()) {
                    $this->reportLost(
                        $worker->expected,
                        $finished[$key],
                        'The worker process running this test exited unexpectedly.'
                            . ($worker->stderrTail() !== '' ? ' Worker stderr: ' . $worker->stderrTail() : ''),
                    );
                }

                $freeSlots[] = $slotOf[$key];

                // Whatever this unit was asked to produce is now
                // available to the units that were waiting for it.
                $artifact = $artifactOf[$key] ?? null;

                if ($artifact !== null) {
                    $available = [...$available, ...$this->readValues($artifact)];
                }

                unset($active[$key], $finished[$key], $slotOf[$key], $artifactOf[$key], $isolatedOf[$key]);
            }
        }
    }

    /**
     * A test that asked for its own process and wrote to STDERR there (D-124)
     * errors, with that text as the message — PHPUnit's rule for a
     * child process (ChildProcessResultProcessor), so a suite moving
     * across keeps its verdicts. The output is never dropped: dropping
     * it hid a debugging probe with nothing saying it was lost.
     */
    private function attributeStderr(TestFinished $event, string $stderr): TestFinished
    {
        $text = trim($stderr);

        if ($text === '') {
            return $event;
        }

        $message = 'The test wrote to STDERR in its separate process:' . PHP_EOL . $text;

        if ($event->failure instanceof Failure) {
            $message .= PHP_EOL . PHP_EOL . 'The test itself also reported: ' . $event->failure->message;
        }

        return new TestFinished(
            $event->test,
            Outcome::Errored,
            $event->duration,
            new Failure($message),
            $event->attempt,
            null,
            $event->issues,
            $event->quarantined,
            $event->property,
            $event->retried,
            $event->snapshots,
            $event->propertyClean,
            $event->snapshotKeys,
            false,
            $event->assertions,
        );
    }

    /**
     * Output that belongs to no isolated test reaches the console as it
     * would have in process: on STDERR, verbatim. A --parallel worker is
     * only a share of the run, so its tests keep the in-process rule.
     */
    private function forwardStderr(string $stderr): void
    {
        if ($stderr !== '') {
            fwrite(STDERR, $stderr);
        }
    }

    /**
     * The first unit whose prerequisites are all available, or — when
     * nothing is running that could still satisfy one — the first unit
     * regardless, so an unproducible dependency ends as a reported
     * untested rather than a stalled run.
     *
     * @param list<PlannedUnit>     $units
     * @param array<string, string> $available
     */
    private function nextReady(array $units, array $available, bool $nothingActive): ?int
    {
        foreach ($units as $index => $planned) {
            $ready = true;

            foreach ($planned->dependsOn as $name) {
                if (!isset($available[$name])) {
                    $ready = false;

                    break;
                }
            }

            if ($ready) {
                return $index;
            }
        }

        return $nothingActive && $units !== [] ? 0 : null;
    }

    /**
     * @return array<string, string>
     */
    private function readValues(string $artifact): array
    {
        if (!is_file($artifact)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($artifact), true);
        @unlink($artifact);

        return is_array($decoded) ? DependencyValues::fromArray($decoded) : [];
    }

    /**
     * Blocks until any active worker has data (or the timeout passes).
     *
     * @param array<int, WorkerProcess> $active
     */
    private function await(array $active): void
    {
        $read = [];

        foreach ($active as $worker) {
            $read[] = $worker->stdout();
            $read[] = $worker->stderr();
        }

        $write  = null;
        $except = null;

        @stream_select($read, $write, $except, 0, (int) (self::SELECT_TIMEOUT_SECONDS * 1_000_000));
    }

    /**
     * Every expected test the dead worker never finished is errored —
     * visibly, on the stream, with the crash diagnostics. A lost
     * worker must never mean silently lost tests.
     *
     * @param list<TestId>        $expected
     * @param array<string, true> $finished
     * @param non-empty-string    $reason
     */
    private function reportLost(array $expected, array $finished, string $reason): void
    {
        foreach ($expected as $id) {
            if (isset($finished[$id->toString()])) {
                continue;
            }

            $this->emitter->emit(new TestStarted($id));
            $this->emitter->emit(new TestFinished($id, Outcome::Errored, 0.0, reason: $reason));
            $this->tally(Outcome::Errored);
        }
    }

    private function tally(Outcome $outcome): void
    {
        $this->counts[$outcome->value]++;
    }

    private function tallyIssues(TestFinished $event): void
    {
        foreach ($event->issues as $issue) {
            $this->issueCounts[match ($issue->kind) {
                IssueKind::Deprecation => 'deprecations',
                IssueKind::Notice      => 'notices',
                IssueKind::Warning     => 'warnings',
            }]++;
        }
    }

    private function tallySummary(RunSummary $summary): void
    {
        $this->counts['pass'] += $summary->passed;
        $this->counts['fail'] += $summary->failed;
        $this->counts['error'] += $summary->errored;
        $this->counts['skip'] += $summary->skipped;
        $this->counts['incomplete'] += $summary->incomplete;
        $this->counts['risky'] += $summary->risky;
        $this->counts['untested'] += $summary->untested;
        $this->issueCounts['deprecations'] += $summary->deprecations;
        $this->issueCounts['notices'] += $summary->notices;
        $this->issueCounts['warnings'] += $summary->warnings;
    }
}
