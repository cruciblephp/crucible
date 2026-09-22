<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document\Renderers\Support;

use LucianoPereira\Crucible\Event\Frame;
use LucianoPereira\Crucible\Event\TestFinished;

use function str_ends_with;

/**
 * Where a failure actually points to, for formats that need a real
 * file/line (SARIF, JSON) rather than the free-text `Failure::$trace`
 * a human reads top-to-bottom. `trace[0]` is the *innermost* frame —
 * for an assertion failure that's Crucible's own `Assert`/`Constraint`
 * internals, not the test's own call site (D-008: assertions are
 * engine code the test author never wrote). The first frame whose file
 * matches the test's own declaring file is the one worth pointing at;
 * an Errored test with no such frame (a fatal from source code under
 * test, not the test itself) falls back to the innermost frame instead
 * of reporting nothing.
 */
final class FailureLocation
{
    private function __construct() {}

    public static function of(TestFinished $test): ?Frame
    {
        $trace = $test->failure?->trace ?? [];

        foreach ($trace as $frame) {
            if (str_ends_with($frame->file, $test->test->file)) {
                return $frame;
            }
        }

        return $trace[0] ?? null;
    }
}
