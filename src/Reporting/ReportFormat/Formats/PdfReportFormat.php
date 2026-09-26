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
use LucianoPereira\Crucible\Reporting\Document\Renderers\PdfRenderer;
use LucianoPereira\Crucible\Reporting\ReportFormat\{ReportContext, ReportFormat, ReportFormatContract};
use LucianoPereira\Crucible\Version;

/**
 * Crucible's own PDF report, as a report-format plugin — registered
 * exactly the same way a third party's own format would be, no
 * privileged path. Delegates to {@see PdfRenderer}, untouched by this
 * refactor.
 */
#[ReportFormat(
    key: 'pdf',
    description: 'Render results as a paginated PDF.',
    comment: 'Cover pages, table of contents, and JPEG/SVG images are all supported. Bookmarks are generated automatically from headings — no config needed for that part.',
    requires: ['ext-dom'],
    params: [
        ['name' => 'output', 'type' => 'string', 'required' => true, 'description' => 'Output file path'],
    ],
)]
final class PdfReportFormat implements ReportFormatContract
{
    public function render(Document $document, ReportContext $context, array $params): string
    {
        return (new PdfRenderer(Version::logo()))->render(
            $document,
            $context->title,
            $context->author,
            $context->producer,
            $context->createdAt,
            $context->runtime,
        );
    }
}
