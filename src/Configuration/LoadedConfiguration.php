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
 * A Configuration together with the file it was loaded from.
 */
final readonly class LoadedConfiguration
{
    /**
     * @param non-empty-string $path
     */
    public function __construct(
        public Configuration $configuration,
        public string $path,
    ) {}
}
