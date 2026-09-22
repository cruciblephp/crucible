<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Architecture;

use LucianoPereira\Crucible\Configuration\Source;
use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

/**
 * The ambient an `arch()` rule reads its universe from (D-088),
 * configured once per run from the loaded configuration — the same
 * shape {@see \LucianoPereira\Crucible\Browser\Browsing} uses, because the
 * problem is the same: a dialect function needs a fact from the
 * configuration without being handed the configuration.
 *
 * One universe per run, shared by every rule: the class map and the
 * per-file reference scan are the expensive part, and a suite of rules
 * asks the same questions of the same files over and over.
 */
final class Architecture
{
    private static ?ArchitectureUniverse $universe = null;

    public static function configure(Source $source, WorkingDirectory $workingDirectory): void
    {
        self::$universe = new ArchitectureUniverse($source, $workingDirectory);
    }

    /**
     * A rule against the configured universe. Without configuration —
     * a dialect used outside a run — the rule targets an empty universe
     * and fails on its own "matches nothing" guard rather than silently
     * passing.
     */
    public static function rule(): ArchRule
    {
        return new ArchRule();
    }

    /**
     * The configured universe, or an empty one when a dialect is used
     * outside a run — a rule then fails its own "matches nothing"
     * guard rather than silently passing.
     */
    public static function universe(): ArchitectureUniverse
    {
        return self::$universe ??= new ArchitectureUniverse(new Source(), new WorkingDirectory('.'));
    }

    public static function reset(): void
    {
        self::$universe = null;
    }
}
