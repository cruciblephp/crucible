<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

/**
 * A coverage source (D-041/D-062). One collection window per test:
 * start(), run the test, stop() returns what executed and clears.
 *
 * The line-value convention is the ecosystem's shared one (pcov
 * emits it xdebug-compatible): > 0 executed, -1 executable but not
 * executed, -2 dead code. Branch data joins the window only when the
 * driver can produce it (xdebug's branch analysis — pcov cannot).
 */
interface CoverageDriver
{
    /**
     * @return non-empty-string
     */
    public function name(): string;

    public function start(): void;

    public function stop(): CoverageWindow;
}
