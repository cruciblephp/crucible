<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ReportFormat;

use LucianoPereira\Crucible\Reporting\Document\Document;

/**
 * The one behavioral contract a report-format plugin implements — its
 * declarative metadata (key, description, requirements, params schema)
 * lives on the {@see ReportFormat} attribute instead, since attributes
 * can't declare methods.
 */
interface ReportFormatContract
{
    /**
     * @param  array<string, mixed>  $params  validated against the class's
     *         #[ReportFormat] params schema before this is ever called
     */
    public function render(Document $document, ReportContext $context, array $params): string;
}
