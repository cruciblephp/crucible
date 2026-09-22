<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ReportFormat;

use DateTimeImmutable;

/**
 * The run metadata every report format might need — title, author,
 * producer, when the run started, how long it took — bundled into one
 * value object so every {@see ReportFormatContract::render()}
 * implementation shares one signature, regardless of how many of these
 * fields the underlying format actually uses.
 */
final readonly class ReportContext
{
    public function __construct(
        public string $title,
        public string $author,
        public string $producer,
        public ?DateTimeImmutable $createdAt,
        public float $runtime,
    ) {}
}
