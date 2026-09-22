<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Double;

use Closure;

/**
 * The spec's Stub value passed to MethodConfigurator::will() — the
 * legacy pairing (`->will($this->throwException($e))`) modern code
 * expresses as a direct willThrowException()/willReturn*() call.
 * Wraps the same behavior shape those already use, so will() is a
 * thin adapter, not a second implementation.
 */
final readonly class Stub
{
    /**
     * @param Closure(list<mixed>, object): mixed $behavior
     */
    public function __construct(
        public Closure $behavior,
    ) {}
}
