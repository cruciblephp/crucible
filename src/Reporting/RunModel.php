<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting;

use LucianoPereira\Crucible\Event\Outcome;
use LucianoPereira\Crucible\Event\TestFinished;

/**
 * The finished-test groupings that Console/Markdown/JUnit/TestDox/Pdf
 * were each independently recomputing: by file, and into problems
 * versus untested by the canonical D-075 rule (blocked -> untested,
 * anything else non-passed -> a problem). `RunSummary` (on
 * `RunFinished`) already centralizes the outcome *counts* — this
 * covers what that doesn't: the actual event lists and their grouping.
 *
 * Reporters with a genuinely different rule keep it, deliberately:
 * `TestDoxReporter` narrows problems to Failed/Errored only,
 * `JUnitXmlWriter` follows the JUnit spec's own granularity (D-018:
 * risky as a plain pass, incomplete as skipped), and `TeamCityReporter`
 * has no untested concept at all. This class is the shared
 * computation, not a mandate to unify every reporter's judgment about
 * what counts as a problem.
 */
final readonly class RunModel
{
    /** @var array<non-empty-string, list<TestFinished>> finished tests by file, in first-seen order */
    public array $sections;

    /** @var list<TestFinished> */
    public array $problems;

    /** @var list<TestFinished> blocked skips (D-075): untested, not defects — kept out of $problems */
    public array $untested;

    /** @var array<string, list<TestFinished>> untested tests grouped by reason, in first-seen order */
    public array $untestedByReason;

    /**
     * @param list<TestFinished> $finished
     */
    public function __construct(array $finished)
    {
        $sections = [];
        $problems = [];
        $untested = [];
        $byReason = [];

        foreach ($finished as $event) {
            $sections[$event->test->file][] = $event;

            if ($event->blocked) {
                $untested[]                                     = $event;
                $byReason[$event->reason ?? 'Could not run.'][] = $event;
            } elseif ($event->outcome !== Outcome::Passed) {
                $problems[] = $event;
            }
        }

        $this->sections         = $sections;
        $this->problems         = $problems;
        $this->untested         = $untested;
        $this->untestedByReason = $byReason;
    }
}
