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
use LucianoPereira\Crucible\Reporting\Document\Renderers\JsonRenderer;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormat, ReportFormatContract};

/**
 * A finished, ready-to-consume JSON summary — as opposed to
 * `--log-events-json`'s raw NDJSON stream, which a consumer must
 * replay and aggregate itself. Registered exactly the same way a
 * third party's own format would be. Delegates to {@see JsonRenderer}.
 */
#[ReportFormat(
    key: 'json',
    description: 'Render results as a finished JSON summary.',
    comment: 'tool/version/summary/problems in one object — for a dashboard or pipeline step that wants a result, not the raw NDJSON event stream to replay.',
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class JsonReportFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return (new JsonRenderer())->render($document, $context);
    }
}
