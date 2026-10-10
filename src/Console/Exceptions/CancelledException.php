<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Console\Exceptions;

use RuntimeException;

/** Thrown when the user cancels a prompt (e.g. by pressing Ctrl+C). */
final class CancelledException extends RuntimeException
{
    public function __construct(string $message = 'Prompt cancelled.')
    {
        parent::__construct($message);
    }
}
