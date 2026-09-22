<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension;

use LucianoPereira\Crucible\Extension\Artifact\Artifact;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

/**
 * The check role (D-078): an extension that inspects the whole project
 * once, after the suite, and presents a run-scoped {@see Artifact} for
 * Crucible to test — the in-process, PHP-native tier of the check surface
 * ({@see CommandGate} is the shell tier for tools with no PHP API).
 *
 * A check never decides its own verdict. It hands over facts — a
 * {@see Artifact\Claim} of what it observed against what it expected —
 * and Crucible owns the outcome, the message, and the exit-code vote. Its
 * {@see CheckFinished} lands inside the run bracket, before `run:finish`.
 */
interface Check extends Extension
{
    /**
     * Names the check in the summary, the reason, and the report.
     *
     * @return non-empty-string
     */
    public function label(): string;

    /**
     * Inspect the project and present the facts. Runs once, after the
     * suite; throwing is a checked error, not a crash (Crucible records it).
     *
     */
    public function inspect(WorkingDirectory $workingDirectory): Artifact;
}
