<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Tests\Reporting\ReportFormat\Fixtures;

use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormatContract};

/** Implements the contract but carries no #[ReportFormat] attribute at all. */
final class NoAttributeFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return '';
    }
}
