<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

use LucianoPereira\Crucible\Clock\SystemClock;
use LucianoPereira\Crucible\Configuration\ExecutionOrder;
use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Envelope;
use LucianoPereira\Crucible\Event\Listener;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;
use LucianoPereira\Crucible\Runner\ResultCache;
use LucianoPereira\Crucible\Runner\RunnerOptions;
use LucianoPereira\Crucible\Runner\Scheduler;
use LucianoPereira\Crucible\Runner\TestRunner;
use LucianoPereira\Crucible\Test\TestDefinition;
use LucianoPereira\Crucible\Test\TestGroup;

use function array_filter;
use function array_values;
use function in_array;

/**
 * Runs a mutant's covering tests in the current process — the warm fork,
 * where the mutant is already active — and reports the first test that
 * fails or errors: the kill. This is what turns {@see MutantApplier}'s
 * "run the covering tests" callback into a concrete answer.
 *
 * The suite is discovered once in the parent (which never runs a test, so
 * the source under mutation stays unloaded and every mutant remains
 * warmable); each fork filters that plan down to the covering ids,
 * schedules them **fastest-first** with the same dependency-safe scheduler
 * the run uses (so a dependent test never runs before its dependency and
 * fakes a kill), and stops on the first defect. It listens to the run's own
 * event stream rather than re-implementing outcome classification.
 */
final class CoveringTestRunner implements Listener
{
    /** @var ?non-empty-string */
    private ?string $killer = null;

    /**
     * @param list<TestGroup>  $groups           the discovered suite
     * @param ResultCache      $durations        the run's timings that define the fastest-first order (D-021/D-077)
     */
    public function __construct(
        private readonly array $groups,
        private readonly WorkingDirectory $workingDirectory,
        private readonly ResultCache $durations = new ResultCache(),
    ) {}

    /**
     * @param list<non-empty-string> $coveringIds fastest-first (D-077)
     *
     * @return ?non-empty-string the first covering test that killed the mutant, or null if none did
     */
    public function firstKiller(array $coveringIds): ?string
    {
        $groups = $this->filter($coveringIds);

        if ($groups === []) {
            return null;
        }

        // Fastest-first, so the cheapest covering test gets the first shot at
        // the kill — with dependency deferral, so reordering never runs a
        // dependent test ahead of its dependency and fakes one.
        $groups = (new Scheduler(ExecutionOrder::Duration, 0, $this->durations))->schedule($groups);

        $this->killer = null;

        $emitter = new Emitter(new SystemClock());
        $emitter->subscribe($this);

        // execute(), not run(): no run:start/finish bracket is wanted here,
        // just the test:finish events. stopOnDefect halts at the first kill.
        (new TestRunner($emitter, new RunnerOptions(stopOnDefect: true, workingDirectory: $this->workingDirectory)))
            ->execute($groups);

        return $this->killer;
    }

    public function handle(Envelope $envelope): void
    {
        $event = $envelope->event;

        if ($this->killer !== null || !$event instanceof TestFinished) {
            return;
        }

        if ($event->outcome === Outcome::Failed || $event->outcome === Outcome::Errored) {
            $this->killer = $event->test->toString();
        }
    }

    /**
     * @param list<non-empty-string> $coveringIds
     *
     * @return list<TestGroup>
     */
    private function filter(array $coveringIds): array
    {
        $filtered = [];

        foreach ($this->groups as $group) {
            $tests = array_values(array_filter(
                $group->tests,
                static fn(TestDefinition $test): bool => in_array($test->id->toString(), $coveringIds, true),
            ));

            if ($tests !== []) {
                $filtered[] = new TestGroup($group->name, $tests, $group->beforeAll, $group->afterAll);
            }
        }

        return $filtered;
    }
}
