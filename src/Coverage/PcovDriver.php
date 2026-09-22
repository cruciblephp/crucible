<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Coverage;

use function pcov\clear;
use function pcov\collect;
use function pcov\start;
use function pcov\stop;

use const pcov\inclusive;

/**
 * Coverage through pcov — the fast line-coverage engine, preferred
 * when loaded. Note the ecosystem reality (D-041): upstream pcov is
 * effectively unmaintained and its legacy PECL build breaks on
 * PHP 8.4/8.5; the working installs come from distribution packagers
 * (Debian/Ubuntu: the Surý packages; macOS: the shivammathur tap).
 * Detection is runtime-based, so a patched build simply works.
 */
final readonly class PcovDriver implements CoverageDriver
{
    public function name(): string
    {
        return 'pcov';
    }

    public function start(): void
    {
        start();
    }

    public function stop(): CoverageWindow
    {
        stop();

        $collected = collect(inclusive);

        clear();

        return new CoverageWindow(Lines::normalize($collected));
    }
}
