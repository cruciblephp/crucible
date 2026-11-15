<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * Licensed under the MIT License.
 */

namespace LucianoPereira\Crucible\Coverage;

/**
 * A named or unnamed coverage target: the files under `$path`, with an
 * optional floor of its own. A name is what `--coverage-min` addresses;
 * an unnamed target is a bare `--coverage-target <path>` and is judged
 * only by `--min` against the pooled total.
 */
final readonly class CoverageTarget
{
    /**
     * @param ?non-empty-string $name
     * @param non-empty-string  $path
     */
    public function __construct(
        public ?string $name,
        public string $path,
        public ?float $minimum = null,
    ) {}
}
