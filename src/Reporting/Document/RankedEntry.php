<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\Document;

/**
 * One entry in a `RankedList` — a slow test, its file, and its time.
 * `$file` is the raw project-relative path, matching `TileEntry`'s
 * own convention (a renderer prettifies it, and — for `PdfRenderer`
 * specifically — uses it to key the "already shown here, don't
 * repeat the time" spotlight set against `TileGrid`'s own raw paths).
 */
final readonly class RankedEntry
{
    /**
     * @param non-empty-string $file
     */
    public function __construct(
        public string $testName,
        public string $file,
        public float $duration,
    ) {}
}
