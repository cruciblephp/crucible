<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Event;

/**
 * The closed vocabulary of stream event names (RESEARCH.md §1, §5).
 *
 * Names use the `scope:action` convention of test2json/Node. Adding a
 * case is a backward-compatible schema change; renaming or removing
 * one is not (the envelope carries a schema version for that).
 */
enum EventName: string
{
    case RunStarted     = 'run:start';
    case RunInterrupted = 'run:interrupt';
    case RunFinished    = 'run:finish';

    case SuiteStarted  = 'suite:start';
    case SuiteFinished = 'suite:finish';

    case TestStarted       = 'test:start';
    case TestFinished      = 'test:finish';
    case TestOutputWritten = 'test:output';

    case CheckFinished = 'check:finish';
}
