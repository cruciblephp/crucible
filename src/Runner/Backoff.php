<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Runner;

/**
 * How retry delays grow (D-043, nextest's vocabulary): none at all,
 * the same pause every time, or doubling per attempt.
 */
enum Backoff: string
{
    case None        = 'none';
    case Fixed       = 'fixed';
    case Exponential = 'exponential';
}
