<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Mutation;

/**
 * A mutant's fate — the mutation-testing lexicon, distinct from a test's
 * {@see \LucianoPereira\Crucible\Event\Outcome}: a mutant is *killed* when a
 * covering test fails against it, *escaped* when every covering test
 * still passes (the mutation the suite cannot see), *errored* when the
 * mutated code crashed the run before a verdict, *timed out* when it ran
 * past its budget (often an infinite loop the mutation introduced), and
 * *not covered* when no test exercised the mutated line at all.
 */
enum MutationOutcome: string
{
    case Killed     = 'killed';
    case Escaped    = 'escaped';
    case Errored    = 'errored';
    case TimedOut   = 'timed_out';
    case NotCovered = 'not_covered';
}
