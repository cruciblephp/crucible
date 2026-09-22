<?php

declare(strict_types=1);
/*
 * This file is part of Crucible.
 *
 * Copyright (c) 2026 Luciano Federico Pereira
 * All rights reserved.
 */

namespace LucianoPereira\Crucible\Reporting\ReportFormat\Formats;

use LucianoPereira\Crucible\Reporting\Document\Document;
use LucianoPereira\Crucible\Reporting\Document\Renderers\SarifRenderer;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormat, ReportFormatContract};

/**
 * SARIF 2.1.0, registered exactly the same way a third party's own
 * format would be. Delegates to {@see SarifRenderer}.
 */
#[ReportFormat(
    key: 'sarif',
    description: 'Render results as a SARIF 2.1.0 report.',
    comment: 'Ingested natively by GitHub Code Scanning (PR inline annotations, the Security tab) and Azure DevOps — failed/errored tests surface as first-class findings, not just a build-status check.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class SarifReportFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return (new SarifRenderer())->render($document, $context);
    }
}
