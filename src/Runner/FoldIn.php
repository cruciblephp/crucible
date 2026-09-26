<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

use LucianoPereira\Crucible\Event\Emitter;
use LucianoPereira\Crucible\Event\Failure;
use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\RunSummary;
use LucianoPereira\Crucible\Event\TestFinished;
use LucianoPereira\Crucible\Event\TestStarted;
use LucianoPereira\Crucible\Test\TestId;

/**
 * Results Crucible did not schedule, folded into the run (D-079, D-130):
 * a Vitest suite, a type-test suite. Each is translated into finished
 * tests first, then emitted here as a `test:start`/`test:finish` pair
 * on the same stream as the PHP suite — one path, so the folded suites
 * share the tree, the report and the exit code, and cannot tally
 * differently from one another.
 */
final readonly class FoldIn
{
    public function __construct(private Emitter $emitter) {}

    /**
     * @param list<TestFinished> $results
     */
    public function emit(array $results): RunSummary
    {
        $summary = new RunSummary();

        foreach ($results as $result) {
            $this->emitter->emit(new TestStarted($result->test));
            $this->emitter->emit($result);
            $summary = $summary->plus(RunSummary::of($result->outcome));
        }

        return $summary;
    }

    /**
     * A suite that could not run at all is one errored test, visible on
     * the stream and failing the run — never a silent pass.
     *
     * @param non-empty-string $reason
     */
    public function couldNotRun(TestId $id, string $reason): RunSummary
    {
        return $this->emit([new TestFinished($id, Outcome::Errored, 0.0, new Failure($reason))]);
    }
}
