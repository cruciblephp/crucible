<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Assert;

use LucianoPereira\Crucible\Exceptions\Exception;
use RuntimeException;

/**
 * A failed assertion. Carries an optional structured comparison so
 * the runner can emit the kernel's Failure diff payload instead of
 * parsing message text. Not final: PropertyFailedError (D-040) is a
 * failed assertion that additionally carries its replay data.
 */
class AssertionFailedError extends RuntimeException implements Exception
{
    public function __construct(
        string $message,
        public readonly ?ComparisonFailure $comparison = null,
    ) {
        parent::__construct($message);
    }
}
