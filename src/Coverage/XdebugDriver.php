<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function xdebug_get_code_coverage;
use function xdebug_start_code_coverage;
use function xdebug_stop_code_coverage;

use const XDEBUG_CC_BRANCH_CHECK;
use const XDEBUG_CC_DEAD_CODE;
use const XDEBUG_CC_UNUSED;

/**
 * Coverage through xdebug (mode=coverage). Unused-line and dead-code
 * analysis are on, so percentages have honest denominators: -1 marks
 * the executable lines a test missed, -2 the lines nothing could
 * execute. Branch analysis (D-062) is a per-run opt-in on top —
 * it costs real collection time and only this driver can do it.
 */
final readonly class XdebugDriver implements CoverageDriver
{
    public function __construct(
        private bool $branchCoverage = false,
        private bool $pathCoverage = false,
    ) {}

    public function name(): string
    {
        return 'xdebug';
    }

    public function start(): void
    {
        $flags = XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE;

        if ($this->branchCoverage) {
            $flags |= XDEBUG_CC_BRANCH_CHECK;
        }

        xdebug_start_code_coverage($flags);
    }

    public function stop(): CoverageWindow
    {
        $collected = xdebug_get_code_coverage();

        xdebug_stop_code_coverage();

        return new CoverageWindow(
            Lines::normalize($collected),
            $this->branchCoverage ? Branches::normalize($collected) : [],
            $this->pathCoverage ? Branches::paths($collected) : [],
        );
    }
}
