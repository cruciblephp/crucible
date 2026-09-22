<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Browser;

/**
 * Work that must make progress while the driver transport waits —
 * the in-process HTTP server above all: while PHP blocks on a
 * navigation reply, the browser is fetching the page from us. The
 * transport selects over these streams alongside its own pipe and
 * calls pump() whenever one wakes.
 */
interface Pumpable
{
    /**
     * Streams whose readability means pump() has work.
     *
     * @return list<resource>
     */
    public function watchStreams(): array;

    /** Serve whatever is ready; never block. */
    public function pump(): void;
}
