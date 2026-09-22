<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension;

use LucianoPereira\Crucible\Filesystem\WorkingDirectory;

/**
 * The no-plugin tier of the extension surface: an external command
 * Crucible orchestrates as part of the run. The command runs after the
 * suite (inside the run bracket, before `run:finish`); Crucible tests its
 * **exit status** — the leanest artifact — and records a run-scoped
 * check. Zero passes; non-zero fails the run with a named reason.
 *
 * The command's own output streams through to the console verbatim;
 * Crucible never parses it. Exit code is the one cross-tool contract that
 * survives version bumps, so it is the only thing read. Tools wanting
 * their per-test results folded into the tree use a report source
 * instead; this tier is the robust floor everything else measures
 * against.
 */
final readonly class CommandGate
{
    /**
     * @param non-empty-string       $label            names the check in the summary, the reason, and the report
     * @param list<non-empty-string> $argv             argv form — no shell, no quoting, no injection surface
     * @param ?WorkingDirectory      $workingDirectory null = the run's working directory
     * @param ?positive-int          $timeout          seconds before Crucible kills the command; null = no limit
     */
    public function __construct(
        public string $label,
        public array $argv,
        public ?WorkingDirectory $workingDirectory = null,
        public ?int $timeout = null,
    ) {}
}
