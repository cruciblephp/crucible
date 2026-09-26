<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

/**
 * One error PHPStan reported on a file (D-137).
 */
final readonly class PhpstanMessage
{
    /**
     * @param string            $file       as PHPStan reported it (absolute)
     * @param int<0, max>       $line       0 when PHPStan names no line
     * @param ?non-empty-string $identifier
     * @param non-empty-string  $message
     */
    public function __construct(
        public string $file,
        public int $line,
        public ?string $identifier,
        public string $message,
    ) {}
}
