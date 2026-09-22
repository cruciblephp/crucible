<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Extension\Artifact;

/**
 * The leanest artifact: the exit status of an external command. Pure
 * facts — the code, how long it took, whether Crucible had to kill it for
 * running past its deadline. The `ok` verdict is not here, because the
 * plugin does not decide it: Crucible tests `code === 0` (see
 * {@see \LucianoPereira\Crucible\Extension\CheckRunner}).
 */
final readonly class ExitStatus implements Artifact
{
    public const int SPAWN_FAILED = -1;

    /**
     * @param float $duration wall-clock seconds, from monotonic time
     */
    public function __construct(
        public int $code,
        public float $duration,
        public bool $timedOut = false,
    ) {}
}
