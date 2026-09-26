<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\CLI;

use function array_filter;
use function array_values;

/**
 * What one PHPStan analysis reported (D-137): the errors placed in files,
 * and the ones it could place nowhere (a configuration problem, an
 * unmatched ignore pattern).
 */
final readonly class PhpstanReport
{
    /**
     * @param list<PhpstanMessage>   $messages
     * @param list<non-empty-string> $general
     */
    public function __construct(
        public array $messages,
        public array $general,
    ) {}

    /**
     * The errors reported on one file.
     *
     * @return list<PhpstanMessage>
     */
    public function forFile(string $file): array
    {
        return array_values(array_filter($this->messages, static fn(PhpstanMessage $message): bool => $message->file === $file));
    }
}
