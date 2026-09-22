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
 * Every way a test can end, matching the PHPUnit 13 outcome spec.
 *
 * One `test:finish` event with an outcome field (Go's Action-field
 * model) instead of one event name per outcome: PHPUnit's six
 * outcomes would make name-per-outcome noisy, and consumers switch
 * on a single field either way (DESIGN.md D-009).
 */
enum Outcome: string
{
    case Passed     = 'pass';
    case Failed     = 'fail';
    case Errored    = 'error';
    case Skipped    = 'skip';
    case Incomplete = 'incomplete';
    case Risky      = 'risky';
}
