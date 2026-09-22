<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Configuration;

/**
 * What a migration produced: the generated crucible.php source and the
 * list of phpunit.xml constructs that had no Crucible equivalent. The
 * notes are duplicated into the generated file's header — a lossy
 * migration must say so in both places.
 */
final readonly class MigrationResult
{
    /**
     * @param non-empty-string       $code
     * @param list<non-empty-string> $notes
     */
    public function __construct(
        public string $code,
        public array $notes,
    ) {}
}
